#!/usr/bin/env sh
# Runs the official conformance suite (obx-conformance from obx-go) against a fresh node in the
# Docker image built from this repository. The suite runs inside the node's container, so its
# local webhook receiver is reachable.
#   scripts/conformance.sh [path-to-obx-go] [extra obx-conformance args]
# Environment: RENDITIONS=client to test a node without server-side image versions;
#              DB=mysql (MySQL 8.4) or DB=mariadb (MariaDB 11) instead of SQLite.
# Without Docker (LOCAL=1, or when Docker is not running): the node runs with this computer's
# PHP (built-in web server with 4 workers, plus bin/obx worker) in a temporary folder, and the
# suite is built with this computer's Go. DB=mysql then needs MYSQL_DSN (for example
# "mysql:host=127.0.0.1;port=3306"), MYSQL_USER and MYSQL_PASSWORD of a server where the
# script may create and drop a database obx_php_conformance_<random>.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
obxgo=${1:-"$here/../obx-go"}
[ $# -gt 0 ] && shift
if [ ! -f "$obxgo/go.mod" ]; then
  echo "usage: scripts/conformance.sh [path-to-obx-go checkout] [obx-conformance args]" >&2
  exit 1
fi
db=${DB:-sqlite}
if [ "${LOCAL:-}" = 1 ] || ! docker info >/dev/null 2>&1; then
  command -v php >/dev/null || { echo "LOCAL=1 needs php (8.2+) on the PATH" >&2; exit 1; }
  command -v go >/dev/null || { echo "LOCAL=1 needs go on the PATH" >&2; exit 1; }
  [ -f "$here/vendor/autoload.php" ] || (cd "$here" && composer install --no-interaction --no-progress --quiet)
  work=$(mktemp -d)
  port=${PORT:-18080}
  dbname=""
  cleanup() {
    [ -n "${server:-}" ] && kill "$server" 2>/dev/null || true
    [ -n "${worker:-}" ] && kill "$worker" 2>/dev/null || true
    pkill -f "php.*-S 127.0.0.1:$port" 2>/dev/null || true
    if [ -n "$dbname" ]; then
      php -r '$p = new PDO(getenv("MYSQL_DSN"), getenv("MYSQL_USER"), getenv("MYSQL_PASSWORD")); $p->exec("DROP DATABASE IF EXISTS " . $argv[1]);' "$dbname" || true
    fi
    rm -rf "$work"
  }
  trap cleanup EXIT
  (cd "$obxgo" && go build -o "$work/obx-conformance" ./cmd/obx-conformance)
  export OBX_PUBLIC_URL="http://127.0.0.1:$port" OBX_SETUP_CODE=CONFORMANCE-1 OBX_ALLOW_PRIVATE_URLS=true \
    OBX_RENDITIONS="${RENDITIONS:-auto}" OBX_DATA_DIR="$work/data" OBX_CONFIG_FILE="$work/no-config.php" \
    OBX_MAIL_TRANSPORT=none PHP_CLI_SERVER_WORKERS=4
  mkdir -p "$work/data"
  case "$db" in
    sqlite) export OBX_DB_DSN="sqlite:$work/data/obx.sqlite" ;;
    mysql|mariadb)
      : "${MYSQL_DSN:?DB=$db with LOCAL=1 needs MYSQL_DSN, MYSQL_USER and MYSQL_PASSWORD}"
      dbname="obx_php_conformance_$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"
      php -r '$p = new PDO(getenv("MYSQL_DSN"), getenv("MYSQL_USER"), getenv("MYSQL_PASSWORD")); $p->exec("CREATE DATABASE " . $argv[1] . " CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");' "$dbname"
      export OBX_DB_DSN="$MYSQL_DSN;dbname=$dbname;charset=utf8mb4" OBX_DB_USER="${MYSQL_USER:-root}" OBX_DB_PASSWORD="${MYSQL_PASSWORD:-}" ;;
    *) echo "DB must be sqlite, mysql or mariadb" >&2; exit 1 ;;
  esac
  php "$here/bin/obx" migrate >/dev/null
  php -d enable_post_data_reading=0 -d upload_max_filesize=512M -d post_max_size=520M -S "127.0.0.1:$port" \
    -t "$here/public" "$here/public/index.php" >"$work/server.log" 2>&1 &
  server=$!
  php "$here/bin/obx" worker >"$work/worker.log" 2>&1 &
  worker=$!
  i=0
  until curl -s -o /dev/null "http://127.0.0.1:$port/.well-known/obx"; do
    i=$((i+1)); [ $i -gt 60 ] && { echo "node did not start" >&2; cat "$work/server.log" >&2; exit 2; }; sleep 1
  done
  "$work/obx-conformance" all --url "http://127.0.0.1:$port" --setup-code CONFORMANCE-1 \
    --operator-hook "$here/scripts/conformance-operator.sh" "$@"
  exit $?
fi
case "$db" in
  sqlite) ;;
  mysql) dbimage=mysql:8.4 ;;
  mariadb) dbimage=mariadb:11 ;;
  *) echo "DB must be sqlite, mysql or mariadb" >&2; exit 1 ;;
esac
net=obx-php-conformance-net
dbname=obx-php-conformance-db
bin=$(mktemp -d)
trap 'docker rm -f obx-php-conformance "$dbname" >/dev/null 2>&1 || true; docker network rm "$net" >/dev/null 2>&1 || true; rm -rf "$bin"' EXIT
arch=$(docker version --format '{{.Server.Arch}}')
docker run --rm -v "$obxgo":/src -v "$bin":/out -w /src -e CGO_ENABLED=0 -e GOOS=linux -e GOARCH="$arch" -e GOTOOLCHAIN=auto golang:latest \
  go build -o /out/obx-conformance ./cmd/obx-conformance
docker build -q -t obx-node-php:conformance "$here" >/dev/null
docker rm -f obx-php-conformance "$dbname" >/dev/null 2>&1 || true
dbargs=""
if [ "$db" != sqlite ]; then
  docker network create "$net" >/dev/null 2>&1 || true
  docker run -d --name "$dbname" --network "$net" -e MYSQL_ROOT_PASSWORD=obx -e MARIADB_ROOT_PASSWORD=obx \
    -e MYSQL_DATABASE=obx -e MARIADB_DATABASE=obx "$dbimage" >/dev/null
  echo "Waiting for $dbimage ..."
  i=0
  until docker run --rm --network "$net" obx-node-php:conformance php -r "new PDO('mysql:host=$dbname;dbname=obx', 'root', 'obx');" >/dev/null 2>&1; do
    i=$((i+1)); [ $i -gt 90 ] && { echo "database did not start" >&2; exit 2; }; sleep 2
  done
  dbargs="--network $net -e OBX_DB_DSN=mysql:host=$dbname;dbname=obx;charset=utf8mb4 -e OBX_DB_USER=root -e OBX_DB_PASSWORD=obx"
fi
# shellcheck disable=SC2086 # dbargs is a list of options
docker run -d --name obx-php-conformance $dbargs -e OBX_PUBLIC_URL=http://localhost:8080 -e OBX_SETUP_CODE=CONFORMANCE-1 \
  -e OBX_ALLOW_PRIVATE_URLS=true -e OBX_RENDITIONS="${RENDITIONS:-auto}" obx-node-php:conformance >/dev/null
docker cp "$bin/obx-conformance" obx-php-conformance:/usr/local/bin/obx-conformance
i=0
until docker exec obx-php-conformance curl -s -o /dev/null http://localhost:8080/.well-known/obx; do
  i=$((i+1)); [ $i -gt 60 ] && { echo "node did not start" >&2; docker logs obx-php-conformance >&2; exit 2; }; sleep 1
done
docker cp "$here/scripts/conformance-operator.sh" obx-php-conformance:/usr/local/bin/obx-operator
docker exec -e OBX_ROOT=/var/www/html obx-php-conformance obx-conformance all --url http://localhost:8080 --setup-code CONFORMANCE-1 \
  --operator-hook /usr/local/bin/obx-operator "$@"

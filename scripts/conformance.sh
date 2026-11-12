#!/usr/bin/env sh
# Runs the official conformance suite (obx-conformance from obx-go) against a fresh node in the
# Docker image built from this repository. The suite runs inside the node's container, so its
# local webhook receiver is reachable.
#   scripts/conformance.sh [path-to-obx-go] [extra obx-conformance args]
# Environment: RENDITIONS=client to test a node without server-side image versions;
#              DB=mysql (MySQL 8.4) or DB=mariadb (MariaDB 11) instead of SQLite.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
obxgo=${1:-"$here/../obx-go"}
[ $# -gt 0 ] && shift
if [ ! -f "$obxgo/go.mod" ]; then
  echo "usage: scripts/conformance.sh [path-to-obx-go checkout] [obx-conformance args]" >&2
  exit 1
fi
db=${DB:-sqlite}
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
docker exec obx-php-conformance obx-conformance all --url http://localhost:8080 --setup-code CONFORMANCE-1 "$@"

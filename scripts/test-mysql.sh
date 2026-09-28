#!/usr/bin/env sh
# Runs the PHPUnit suite against MySQL 8 and MariaDB 11 (in Docker) instead of SQLite.
# Usage: scripts/test-mysql.sh [mysql|mariadb] [phpunit args]
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
flavour=${1:-mysql}
[ $# -gt 0 ] && shift
case "$flavour" in
  mysql) image=mysql:8.4 ;;
  mariadb) image=mariadb:11 ;;
  *) echo "usage: scripts/test-mysql.sh [mysql|mariadb] [phpunit args]" >&2; exit 1 ;;
esac
net=obx-php-test-net
name=obx-php-test-$flavour
docker network create "$net" >/dev/null 2>&1 || true
docker rm -f "$name" >/dev/null 2>&1 || true
docker run -d --name "$name" --network "$net" -e MYSQL_ROOT_PASSWORD=obx -e MARIADB_ROOT_PASSWORD=obx "$image" >/dev/null
trap 'docker rm -f "$name" >/dev/null 2>&1 || true' EXIT
docker build -q -t obx-node-php-test -f "$here/docker/test.Dockerfile" "$here/docker" >/dev/null
echo "Waiting for $image ..."
i=0
until docker run --rm --network "$net" obx-node-php-test php -r "new PDO('mysql:host=$name', 'root', 'obx');" >/dev/null 2>&1; do
  i=$((i+1)); [ $i -gt 90 ] && { echo "database did not start" >&2; exit 1; }; sleep 2
done
docker run --rm --network "$net" -v "$here":/app -w /app \
  -e OBX_TEST_MYSQL_DSN="mysql:host=$name" -e OBX_TEST_MYSQL_USER=root -e OBX_TEST_MYSQL_PASSWORD=obx \
  obx-node-php-test sh -c 'composer install --no-interaction --no-progress --quiet && vendor/bin/phpunit "$@"' -- "$@"

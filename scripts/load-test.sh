#!/usr/bin/env sh
# Seeded load test: a node in the production Docker image gets 10 000 products, 60 000
# variants and 40 000 images (metadata; one shared JPEG), written through its own write path,
# then the read paths are timed from a second container: the full /changes snapshot, product
# pages, EAN lookups, product detail, the retailer portal list/search/filters/facets and the
# admin list. Prints a Markdown table (docs/performance.md keeps the latest numbers).
#   scripts/load-test.sh [products]            default 10000; DB=mysql for MySQL 8.4;
#                                              KEEP=1 leaves the node running for EXPLAIN
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
products=${1:-10000}
net=obx-load-net
node=obx-load-node
db=obx-load-mysql
cleanup() {
  [ "${KEEP:-0}" = 1 ] && { echo "KEEP=1: containers $node and network $net are left running" >&2; return; }
  docker rm -f "$node" "$db" >/dev/null 2>&1 || true
  docker network rm "$net" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker rm -f "$node" "$db" >/dev/null 2>&1 || true
docker network rm "$net" >/dev/null 2>&1 || true
docker network create "$net" >/dev/null
docker build -q -t obx-node-php:load "$here" >/dev/null
docker build -q -t obx-node-php-test -f "$here/docker/test.Dockerfile" "$here/docker" >/dev/null
env_db=""
if [ "${DB:-sqlite}" = mysql ]; then
  docker run -d --name "$db" --network "$net" -e MYSQL_ROOT_PASSWORD=obx -e MYSQL_DATABASE=obx mysql:8.4 >/dev/null
  until docker exec "$db" mysql -uroot -pobx -e 'SELECT 1' obx >/dev/null 2>&1; do sleep 2; done
  env_db="-e OBX_DB_DSN=mysql:host=$db;dbname=obx;charset=utf8mb4 -e OBX_DB_USER=root -e OBX_DB_PASSWORD=obx"
fi
# shellcheck disable=SC2086 # env_db is a list of options
docker run -d --name "$node" --network "$net" $env_db -e OBX_PUBLIC_URL=http://$node:8080 -e OBX_WORKER=0 \
  -e OBX_RATE_LIMIT_FACTOR=1000 -e OBX_ACCESS_LOG=0 -v "$here/tests/Load":/load:ro obx-node-php:load >/dev/null
until docker exec "$node" curl -fs -o /dev/null http://localhost:8080/healthz; do sleep 1; done
sleep 2
echo "Seeding $products products ..." >&2
docker exec -u www-data "$node" php -d memory_limit=1G /load/load.php seed /var/www/html /tmp/load.json "$products"
docker exec "$node" cat /tmp/load.json > "${TMPDIR:-/tmp}/obx-load.json"
echo "Timing the read paths ..." >&2
docker run --rm --network "$net" -v "$here/tests/Load":/load:ro -v "${TMPDIR:-/tmp}/obx-load.json":/in.json:ro obx-node-php-test \
  php /load/load.php bench "http://$node:8080" /in.json
python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print('\nSeeded (%s): %s in %s s' % (d['driver'], d['counts'], d['seed_seconds']))" "${TMPDIR:-/tmp}/obx-load.json"

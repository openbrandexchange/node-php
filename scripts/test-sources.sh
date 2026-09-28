#!/usr/bin/env sh
# Runs the scheduled-import tests against real servers in Docker: an S3-compatible store
# (SeaweedFS) and an SFTP server (atmoz/sftp) holding the Kestrel fixtures.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
net=obx-php-test-net
work=$(mktemp -d)
trap 'docker rm -f obx-php-s3 obx-php-sftp >/dev/null 2>&1 || true; rm -rf "$work"' EXIT
docker network create "$net" >/dev/null 2>&1 || true
printf '{"identities":[{"name":"obx","credentials":[{"accessKey":"obxtest","secretKey":"obxtestsecret"}],"actions":["Admin","Read","Write","List","Tagging"]}]}' > "$work/s3.json"
mkdir -p "$work/sftp/export/images"
cp "$here/tests/fixtures/spec/kestrel/products.csv" "$here/tests/fixtures/spec/kestrel/assets.csv" "$work/sftp/export/"
cp "$here"/tests/fixtures/spec/kestrel/images/*.png "$work/sftp/export/images/"
docker rm -f obx-php-s3 obx-php-sftp >/dev/null 2>&1 || true
docker run -d --name obx-php-s3 --network "$net" -v "$work/s3.json:/etc/s3.json:ro" chrislusf/seaweedfs server -s3 -s3.config=/etc/s3.json -s3.port=8333 >/dev/null
docker run -d --name obx-php-sftp --network "$net" -v "$work/sftp:/home/brand/upload:ro" atmoz/sftp brand:brandpass:1001 >/dev/null
docker build -q -t obx-node-php-test -f "$here/docker/test.Dockerfile" "$here/docker" >/dev/null
echo "Waiting for the servers ..."
i=0
until docker run --rm --network "$net" obx-node-php-test sh -c 'php -r "exit(@fsockopen(\"obx-php-s3\", 8333) && @fsockopen(\"obx-php-sftp\", 22) ? 0 : 1);"' >/dev/null 2>&1; do
  i=$((i+1)); [ $i -gt 60 ] && { echo "servers did not start" >&2; exit 1; }; sleep 2
done
sleep 3
docker run --rm --network "$net" -v "$here":/app -w /app \
  -e OBX_TEST_S3_ENDPOINT=http://obx-php-s3:8333 -e OBX_TEST_SFTP_HOST=obx-php-sftp \
  obx-node-php-test sh -c 'composer install --no-interaction --no-progress --quiet && vendor/bin/phpunit --filter SourcesTest "$@"' -- "$@"

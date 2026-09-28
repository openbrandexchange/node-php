#!/usr/bin/env sh
# Runs the PHPUnit suite in Docker (PHP 8.3 with gd, zip, pdo_sqlite, pdo_mysql). No local PHP needed.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
image=obx-node-php-test
docker build -q -t "$image" -f "$here/docker/test.Dockerfile" "$here/docker" >/dev/null
docker run --rm -v "$here":/app -w /app "$image" sh -c 'composer install --no-interaction --no-progress --quiet && vendor/bin/phpunit "$@"' -- "$@"

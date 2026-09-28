#!/usr/bin/env sh
# Runs the PHPUnit suite in Docker (PHP with gd, zip, pdo_sqlite, pdo_mysql). No local PHP needed.
#   scripts/test.sh [phpunit args]          PHP 8.3
#   PHP=8.4 scripts/test.sh                 another PHP version (8.2, 8.3, 8.4)
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
php=${PHP:-8.3}
image=obx-node-php-test
[ "$php" = 8.3 ] || image="obx-node-php-test:$php"
docker build -q -t "$image" --build-arg PHP="$php" -f "$here/docker/test.Dockerfile" "$here/docker" >/dev/null
docker run --rm -v "$here":/app -w /app "$image" sh -c 'composer install --no-interaction --no-progress --quiet && vendor/bin/phpunit "$@"' -- "$@"

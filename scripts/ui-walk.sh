#!/usr/bin/env sh
# Walks the main brand-admin and retailer flows of the real web interface (public/ui) in a
# headless browser against a fresh node in Docker: the web installer, setup wizard, CSV + zip
# import, access request -> approval -> invitation (read from a Mailpit inbox) -> retailer
# login, browsing, EAN list, zips and CSV, a background download, API and upload keys, a
# scheduled import from a web address and the statistics. Every main screen is checked with
# axe (WCAG 2.1 A/AA) and the retailer logs in with the keyboard only. Needs Docker and Node.js.
#   scripts/ui-walk.sh [path-to-spec-checkout]      (default: the fixtures in tests/fixtures/spec)
# Environment: RENDITIONS=client for a node without server-side image versions; PORT (8091).
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
spec=${1:-"$here/tests/fixtures/spec"}
[ -d "$spec/fixtures/kestrel" ] && spec="$spec/fixtures"
fixtures="$spec/kestrel"
[ -f "$fixtures/products.csv" ] || { echo "usage: scripts/ui-walk.sh [path-to-spec-checkout]" >&2; exit 1; }
port=${PORT:-8091}
net=obx-ui-walk
work=$(mktemp -d)
cleanup() {
  docker rm -f obx-ui-walk-node obx-ui-walk-mail obx-ui-walk-feed obx-ui-walk-installer >/dev/null 2>&1 || true
  docker network rm "$net" >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT
cleanup
work=$(mktemp -d)
(cd "$fixtures/images" && zip -q -r "$work/images.zip" .)
mkdir -p "$work/feed"
sed 's/Merino Crew Sweater,/Merino Crew Sweater (nightly),/' "$fixtures/products.csv" > "$work/feed/products.csv"
docker network create "$net" >/dev/null
docker build -q -t obx-node-php:ui-walk "$here" >/dev/null
docker build -q -t obx-node-php-test -f "$here/docker/test.Dockerfile" "$here/docker" >/dev/null
# A copy of the release files that is not installed yet, for the web installer. A fresh
# checkout has no vendor/ (the release zip ships it), so the copy installs its own.
docker run -d --name obx-ui-walk-installer -p 8092:8092 -v "$here":/src:ro obx-node-php-test sh -c \
  'cp -R /src /app2 && cd /app2 && rm -f config/config.php && find data -mindepth 1 ! -name .htaccess ! -name .gitkeep -exec rm -rf {} + ;
   [ -f vendor/autoload.php ] || composer install --no-dev --no-interaction --no-progress --quiet;
   php -d enable_post_data_reading=0 -S 0.0.0.0:8092 -t public public/index.php' >/dev/null
docker run -d --name obx-ui-walk-mail --network "$net" -p 8025:8025 axllent/mailpit >/dev/null
docker run -d --name obx-ui-walk-feed --network "$net" -v "$work/feed":/srv:ro -w /srv python:3.12-alpine python -m http.server 8000 >/dev/null
docker run -d --name obx-ui-walk-node --network "$net" -p "$port":8080 \
  -e OBX_PUBLIC_URL="http://localhost:$port" -e OBX_SETUP_CODE=UI-WALK-2026 -e OBX_ALLOW_PRIVATE_URLS=true \
  -e OBX_RENDITIONS="${RENDITIONS:-auto}" -e OBX_MAIL_TRANSPORT=smtp -e OBX_SMTP_HOST=obx-ui-walk-mail \
  -e OBX_SMTP_PORT=1025 -e OBX_SMTP_ENCRYPTION=none -e OBX_MAIL_FROM=portal@kestrel.example obx-node-php:ui-walk >/dev/null
i=0
until curl -s -o /dev/null "http://localhost:$port/admin/api/public/branding"; do
  i=$((i+1)); [ $i -gt 60 ] && { echo "node did not start" >&2; exit 2; }; sleep 1
done
cd "$here/tests/ui"
[ -d node_modules/playwright ] || npm install --no-audit --no-fund --silent
npx playwright install chromium >/dev/null
until curl -s -o /dev/null http://localhost:8092/install; do sleep 1; done
INSTALLER=http://localhost:8092 BASE="http://localhost:$port" MAILPIT=http://localhost:8025 SETUP_CODE=UI-WALK-2026 FIXTURES="$fixtures" \
  IMAGES_ZIP="$work/images.zip" FEED_URL=http://obx-ui-walk-feed:8000/products.csv node walk.mjs

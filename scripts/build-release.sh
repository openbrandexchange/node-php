#!/usr/bin/env sh
# Builds the release zip for ordinary web hosting (FTP upload): production dependencies
# (vendor/) included, the web interface included when public/ui holds a build.
#   scripts/build-release.sh            -> build/obx-portal-php-<version>.zip
# Needs Docker (uses the composer:2 image); no local PHP required.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
version=$(sed -n "s/.*public const VERSION = '\([^']*\)'.*/\1/p" "$here/src/Config.php")
stage="$here/build/stage/obx-portal"
out="$here/build/obx-portal-php-$version.zip"
rm -rf "$here/build/stage" "$out"
mkdir -p "$stage"
# Copy the tracked sources (no tests, no dev tooling, no local data).
for item in public src bin resources config data docs .htaccess composer.json composer.lock LICENSE NOTICE README.md NOTES.md CHANGELOG.md SECURITY.md; do
  [ -e "$here/$item" ] && cp -R "$here/$item" "$stage/"
done
rm -f "$stage/config/config.php"
find "$stage/data" -mindepth 1 ! -name .htaccess ! -name .gitkeep -exec rm -rf {} + 2>/dev/null || true
if [ ! -f "$stage/public/ui/index.html" ]; then
  echo "public/ui has no web interface build; run scripts/update-ui.sh first" >&2
  exit 1
fi
docker run --rm -v "$stage":/app -w /app composer:2 \
  composer install --no-dev --no-interaction --no-progress --optimize-autoloader --classmap-authoritative \
  --ignore-platform-req=ext-gd --ignore-platform-req=ext-zip --quiet
rm -f "$stage/composer.phar"
chmod +x "$stage/bin/obx"
(cd "$here/build/stage" && zip -qr -X "$out" obx-portal)
rm -rf "$here/build/stage"
echo "Built $out ($(du -h "$out" | cut -f1))"
(cd "$here/build" && { command -v sha256sum >/dev/null && sha256sum "$(basename "$out")" || shasum -a 256 "$(basename "$out")"; })

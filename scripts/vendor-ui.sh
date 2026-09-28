#!/usr/bin/env sh
# Puts the shared web interface (admin-ui build) into public/ui so the node serves it.
#   scripts/vendor-ui.sh [path-to-admin-ui-checkout | path-to-built-dist]
# With a checkout it runs `npm ci && npm run build` there (needs Node.js), then copies dist/.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
src=${1:-"$here/../admin-ui"}
if [ -f "$src/index.html" ] && [ -d "$src/assets" ]; then
  dist="$src"
elif [ -f "$src/package.json" ]; then
  (cd "$src" && npm ci --no-audit --no-fund && npm run build)
  dist="$src/dist"
else
  echo "usage: scripts/vendor-ui.sh [admin-ui checkout | built dist folder]" >&2
  exit 1
fi
find "$here/public/ui" -mindepth 1 ! -name README.md -exec rm -rf {} + 2>/dev/null || true
cp -R "$dist"/. "$here/public/ui/"
# E-mails need the locale files (dist/locales/<lang>.json); fall back to the sources.
if [ ! -d "$here/public/ui/locales" ] && [ -d "$src/src/locales" ]; then
  mkdir -p "$here/public/ui/locales"
  cp "$src"/src/locales/*.json "$here/public/ui/locales/"
fi
echo "Web interface copied from $dist to public/ui ($(find "$here/public/ui" -type f | wc -l | tr -d ' ') files)"

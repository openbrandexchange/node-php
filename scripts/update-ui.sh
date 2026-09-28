#!/usr/bin/env sh
# Re-copies the built web interface (admin-ui's dist/ folder) into public/ui, so this node
# serves the real brand admin and retailer portal. It never builds anything.
#   scripts/update-ui.sh [path-to-admin-ui-checkout] [git ref]   (defaults: ../admin-ui, HEAD)
# In a git checkout it copies dist/ as committed at the ref, so unfinished local changes in
# admin-ui never end up here. UI_FROM_WORKTREE=1 copies the working folder instead.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
src=${1:-"$here/../admin-ui"}
ref=${2:-HEAD}
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
if [ "${UI_FROM_WORKTREE:-}" != 1 ] && git -C "$src" rev-parse --git-dir >/dev/null 2>&1; then
  git -C "$src" archive --format=tar "$ref" dist | tar -x -C "$tmp"
  version=$(git -C "$src" describe --tags --always "$ref")
else
  cp -R "$src/dist" "$tmp/dist"
  version=$(git -C "$src" describe --tags --always --dirty 2>/dev/null || echo unknown)
fi
dist="$tmp/dist"
if [ ! -f "$dist/index.html" ] || [ ! -d "$dist/assets" ] || [ ! -d "$dist/locales" ]; then
  echo "No built web interface in $src/dist (expected index.html, assets/ and locales/)." >&2
  echo "usage: scripts/update-ui.sh [path-to-admin-ui-checkout] [git ref]" >&2
  exit 1
fi
find "$here/public/ui" -mindepth 1 ! -name README.md -exec rm -rf {} +
cp -R "$dist"/. "$here/public/ui/"
printf '%s\n' "$version" > "$here/public/ui/VERSION"
echo "Web interface $version copied to public/ui ($(find "$here/public/ui" -type f | wc -l | tr -d ' ') files)"

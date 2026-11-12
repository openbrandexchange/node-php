#!/usr/bin/env sh
# Copies the spec fixtures (Kestrel Outfitters catalog, expected objects, ingest replay script,
# import cases) from a checkout of openbrandexchange/spec into tests/fixtures/spec, and the
# vocabularies (names of material, part, qualifier and care codes) into resources/vocabularies,
# where the node reads them at runtime. Tests use these copies so they run without the spec
# repository.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
spec=${1:-"$here/../spec"}
if [ ! -d "$spec/fixtures" ]; then
  echo "usage: scripts/sync-spec.sh [path-to-spec-checkout]" >&2
  exit 1
fi
dest="$here/tests/fixtures/spec"
rm -rf "$dest"
mkdir -p "$dest"
cp -R "$spec/fixtures/kestrel" "$spec/fixtures/expected" "$spec/fixtures/ingest" "$spec/fixtures/examples" "$spec/fixtures/imports" "$dest/"
(cd "$spec" && git rev-parse HEAD 2>/dev/null || echo unknown) > "$dest/SPEC_COMMIT"
vocab="$here/resources/vocabularies"
rm -rf "$vocab"
mkdir -p "$vocab"
cp "$spec"/vocabularies/*.json "$vocab/"
cp "$dest/SPEC_COMMIT" "$vocab/SPEC_COMMIT"
echo "Copied spec fixtures ($(cat "$dest/SPEC_COMMIT")) to tests/fixtures/spec and vocabularies to resources/vocabularies"

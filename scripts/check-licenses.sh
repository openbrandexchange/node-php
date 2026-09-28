#!/usr/bin/env sh
# Fails when a production dependency (the PHP packages shipped in vendor/ of the release zip
# and the Docker image) has a licence that is not on the allow list below. The list holds
# permissive licences that combine with AGPL-3.0-only.
#   scripts/check-licenses.sh            (Docker, no local PHP needed)
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
allowed='MIT BSD-2-Clause BSD-3-Clause Apache-2.0 ISC LGPL-2.1-only LGPL-2.1-or-later LGPL-3.0-only LGPL-3.0-or-later MPL-2.0 Zlib'
docker run --rm -v "$here":/app -w /app composer:2 composer licenses --no-dev --format=json --no-interaction \
  | ALLOWED="$allowed" python3 -c '
import json, os, sys
allowed = set(os.environ["ALLOWED"].split())
data = json.load(sys.stdin)
bad = []
for name, info in sorted(data.get("dependencies", {}).items()):
    lic = info.get("license") or []
    ok = any(l in allowed for l in lic)
    print(("ok   " if ok else "FAIL ") + name + " " + info.get("version", "") + " " + (" OR ".join(lic) or "none"))
    if not ok:
        bad.append(name)
if bad:
    sys.exit("Licences not on the allow list: " + ", ".join(bad))
print("All %d production dependencies have allowed licences." % len(data.get("dependencies", {})))
'

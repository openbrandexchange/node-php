#!/usr/bin/env sh
# Runs the official conformance suite (obx-conformance from obx-go) against a fresh node in the
# Docker image built from this repository. The suite runs inside the node's container, so its
# local webhook receiver is reachable.
#   scripts/conformance.sh [path-to-obx-go] [extra obx-conformance args]
# Environment: RENDITIONS=client to test a node without server-side image versions.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
obxgo=${1:-"$here/../obx-go"}
[ $# -gt 0 ] && shift
if [ ! -f "$obxgo/go.mod" ]; then
  echo "usage: scripts/conformance.sh [path-to-obx-go checkout] [obx-conformance args]" >&2
  exit 1
fi
bin=$(mktemp -d)
trap 'docker rm -f obx-php-conformance >/dev/null 2>&1 || true; rm -rf "$bin"' EXIT
arch=$(docker version --format '{{.Server.Arch}}')
docker run --rm -v "$obxgo":/src -v "$bin":/out -w /src -e CGO_ENABLED=0 -e GOOS=linux -e GOARCH="$arch" -e GOTOOLCHAIN=auto golang:latest \
  go build -o /out/obx-conformance ./cmd/obx-conformance
docker build -q -t obx-node-php:conformance "$here" >/dev/null
docker rm -f obx-php-conformance >/dev/null 2>&1 || true
docker run -d --name obx-php-conformance -e OBX_PUBLIC_URL=http://localhost:8080 -e OBX_SETUP_CODE=CONFORMANCE-1 \
  -e OBX_ALLOW_PRIVATE_URLS=true -e OBX_RENDITIONS="${RENDITIONS:-auto}" obx-node-php:conformance >/dev/null
docker cp "$bin/obx-conformance" obx-php-conformance:/usr/local/bin/obx-conformance
i=0
until docker exec obx-php-conformance curl -s -o /dev/null http://localhost:8080/.well-known/obx; do
  i=$((i+1)); [ $i -gt 60 ] && { echo "node did not start" >&2; exit 2; }; sleep 1
done
docker exec obx-php-conformance obx-conformance all --url http://localhost:8080 --setup-code CONFORMANCE-1 "$@"

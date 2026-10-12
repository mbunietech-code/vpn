#!/bin/sh
# Downloads sing-box for Apple Silicon + Intel and merges them into one
# universal binary at macos/engine/sing-box (bundled by embed-engine.sh).
# Use the same version as the Windows build and the server.
#
#   bash macos/engine-scripts/fetch-engine.sh 1.14.x
set -eu

VER="${1:?usage: fetch-engine.sh <sing-box version>}"
OUT="$(cd "$(dirname "$0")/.." && pwd)/engine"
BASE="https://github.com/SagerNet/sing-box/releases/download/v${VER}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

for arch in arm64 amd64; do
  name="sing-box-${VER}-darwin-${arch}"
  echo "==> $name"
  curl -fsSL "$BASE/$name.tar.gz" -o "$TMP/$name.tar.gz"
  tar xzf "$TMP/$name.tar.gz" -C "$TMP"
done

mkdir -p "$OUT"
lipo -create \
  "$TMP/sing-box-${VER}-darwin-arm64/sing-box" \
  "$TMP/sing-box-${VER}-darwin-amd64/sing-box" \
  -output "$OUT/sing-box"
chmod 755 "$OUT/sing-box"
echo "$VER" > "$OUT/VERSION"
lipo -info "$OUT/sing-box"
"$OUT/sing-box" version | head -1

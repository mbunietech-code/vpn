#!/bin/sh
# Builds ios/Frameworks/Libbox.xcframework (not in git, ~370 MB) from
# sing-box, the same version as Windows, macOS and the server.
# Needs Go and SagerNet's gomobile:
#   go install github.com/sagernet/gomobile/cmd/gomobile@v0.1.13
#   go install github.com/sagernet/gomobile/cmd/gobind@v0.1.13
#
#   sh ios/engine-scripts/build-libbox.sh 1.14.0 [path/to/sing-box checkout]
set -eu

VER="${1:?usage: build-libbox.sh <sing-box version> [sing-box checkout]}"
OUT="$(cd "$(dirname "$0")/.." && pwd)/Frameworks"
SRC="${2:-}"
if [ -z "$SRC" ]; then
  SRC="$(mktemp -d)/sing-box"
  git clone --depth 1 -b "v$VER" https://github.com/SagerNet/sing-box.git "$SRC"
fi
cd "$SRC"
[ "$(git describe --tags)" = "v$VER" ] || { echo "$SRC is not at v$VER" >&2; exit 1; }

# iOS gets the with_low_memory tag (the extension's limit is about 50 MB).
go run ./cmd/internal/build_libbox -target apple -platform ios,iossimulator
# build_libbox moves the result to ../sing-box-for-apple when that exists.
[ -d Libbox.xcframework ] || mv ../sing-box-for-apple/Libbox.xcframework .

mkdir -p "$OUT"
rm -rf "$OUT/Libbox.xcframework"
mv Libbox.xcframework "$OUT/"
echo "$VER" > "$OUT/LIBBOX_VERSION"
echo "Libbox $VER -> $OUT/Libbox.xcframework"

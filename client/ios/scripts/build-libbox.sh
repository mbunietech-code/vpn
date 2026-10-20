#!/bin/bash
# Builds Libbox.xcframework (sing-box for iOS + simulator) into
# client/ios/Frameworks/ for the PacketTunnel extension. Not in git (~365 MB).
#
#   bash ios/scripts/build-libbox.sh [version]     (default 1.14.0, same as desktop)
#
# Needs Go and Xcode; installs sagernet/gomobile on first run.
set -euo pipefail

VERSION="${1:-1.14.0}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${SINGBOX_SRC:-$HOME/src/sing-box-$VERSION}"
export PATH="$(go env GOPATH)/bin:$PATH"

if ! command -v gomobile >/dev/null || ! command -v gobind >/dev/null; then
  go install github.com/sagernet/gomobile/cmd/gomobile@v0.1.13
  go install github.com/sagernet/gomobile/cmd/gobind@v0.1.13
fi

if [ ! -d "$SRC" ]; then
  git clone --depth 1 --branch "v$VERSION" https://github.com/SagerNet/sing-box.git "$SRC"
fi

cd "$SRC"
# build_libbox moves the result into ../sing-box-for-apple when that exists;
# build from a clean name so it stays here.
rm -rf Libbox.xcframework
go run ./cmd/internal/build_libbox -target apple -platform ios,iossimulator
OUT=Libbox.xcframework
[ -d "$OUT" ] || OUT=../sing-box-for-apple/Libbox.xcframework

mkdir -p "$HERE/Frameworks"
rm -rf "$HERE/Frameworks/Libbox.xcframework"
mv "$OUT" "$HERE/Frameworks/Libbox.xcframework"
echo "==> $HERE/Frameworks/Libbox.xcframework (sing-box $VERSION)"

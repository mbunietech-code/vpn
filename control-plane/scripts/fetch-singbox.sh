#!/usr/bin/env bash
# Downloads the sing-box release binaries and drops the extracted executables
# into storage/app/bin/ so the MVPN desktop app can pull them from
#   https://vpn.mbuniehub.com/bin/sing-box/<target>
#
# Run on the control-plane host (Hostinger SSH):
#   bash control-plane/scripts/fetch-singbox.sh 1.11.15
set -euo pipefail

VER="${1:-1.11.15}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$HERE/storage/app/bin"
BASE="https://github.com/SagerNet/sing-box/releases/download/v${VER}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$OUT"
echo "$VER" > "$OUT/VERSION"

fetch() { # <asset> <archive-type> <inner-binary> <dest-name>
  local asset="$1" type="$2" inner="$3" dest="$4"
  echo "==> $asset"
  curl -fsSL "$BASE/$asset" -o "$TMP/$asset"
  case "$type" in
    zip) (cd "$TMP" && unzip -oq "$asset") ;;
    tgz) (cd "$TMP" && tar xzf "$asset") ;;
  esac
  cp "$TMP/${asset%.*}"*/"$inner" "$OUT/$dest" 2>/dev/null \
    || cp "$TMP/$(basename "$asset" | sed -E 's/\.(zip|tar\.gz)$//')/$inner" "$OUT/$dest"
  chmod +x "$OUT/$dest" || true
}

fetch "sing-box-${VER}-windows-amd64.zip"   zip "sing-box.exe" "sing-box-windows-amd64.exe"
fetch "sing-box-${VER}-linux-amd64.tar.gz"  tgz "sing-box"     "sing-box-linux-amd64"
fetch "sing-box-${VER}-darwin-arm64.tar.gz" tgz "sing-box"     "sing-box-darwin-arm64"
fetch "sing-box-${VER}-darwin-amd64.tar.gz" tgz "sing-box"     "sing-box-darwin-amd64"

echo
ls -la "$OUT"
echo "done — verify: curl -I https://vpn.mbuniehub.com/bin/sing-box/windows-amd64"

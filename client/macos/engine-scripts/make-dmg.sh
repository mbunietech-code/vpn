#!/bin/sh
# Builds the shareable installer: build/macos/Mbunie-VPN-<version>-macos.dmg
# (the app, an Applications shortcut and "How to open.txt").
# Run from client/ after fetch-engine.sh:   sh macos/engine-scripts/make-dmg.sh
#
# Without a Developer ID the app is ad-hoc signed, so users must allow it once
# in System Settings → Privacy & Security → Open Anyway (see the note below).
set -eu

cd "$(dirname "$0")/../.."
[ -f macos/engine/sing-box ] || { echo "macos/engine/sing-box missing - run fetch-engine.sh first" >&2; exit 1; }

flutter build macos --release
APP="build/macos/Build/Products/Release/Mbunie VPN.app"
VER=$(/usr/libexec/PlistBuddy -c "Print CFBundleShortVersionString" "$APP/Contents/Info.plist")
OUT="build/macos/Mbunie-VPN-$VER-macos.dmg"

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
ditto "$APP" "$STAGE/Mbunie VPN.app"
ln -s /Applications "$STAGE/Applications"
cat > "$STAGE/How to open.txt" <<'EOF'
Mbunie VPN for Mac (macOS 12 or newer, Apple Silicon and Intel)

1. Drag "Mbunie VPN" onto "Applications".
2. Open it from Applications. macOS will say it cannot verify the app.
   Click "Done" (not "Move to Trash").
3. Open System Settings > Privacy & Security, scroll down and click
   "Open Anyway" next to "Mbunie VPN", then confirm with your password.
   (Only needed the first time.)
4. Sign in, press Connect, and enter your Mac password once when asked.
   This lets the VPN engine create the connection; later connects
   do not ask again.

Help: support@mbuniehub.com
EOF

rm -f "$OUT"
hdiutil create -volname "Mbunie VPN" -srcfolder "$STAGE" -fs HFS+ -format UDZO -imagekey zlib-level=9 -ov "$OUT"
hdiutil verify "$OUT"
ls -lh "$OUT"

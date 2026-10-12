#!/bin/sh
# Xcode build phase (Runner): puts the sing-box engine and its root helper
# scripts into the app.
#   macos/engine/sing-box   universal binary, not in git (see fetch-engine.sh)
#   → Contents/MacOS/sing-box, Contents/Resources/engine/{mvpn-engine,install-helper.sh}
set -eu

APP="$TARGET_BUILD_DIR/$WRAPPER_NAME"
RES="$APP/Contents/Resources/engine"

mkdir -p "$RES"
cp "$PROJECT_DIR/engine-scripts/mvpn-engine" "$PROJECT_DIR/engine-scripts/install-helper.sh" "$RES/"
chmod 755 "$RES/mvpn-engine" "$RES/install-helper.sh"

if [ -f "$PROJECT_DIR/engine/sing-box" ]; then
  cp "$PROJECT_DIR/engine/sing-box" "$APP/Contents/MacOS/sing-box"
  chmod 755 "$APP/Contents/MacOS/sing-box"
  IDENTITY="${EXPANDED_CODE_SIGN_IDENTITY:--}"
  if [ "$IDENTITY" = "-" ]; then
    codesign --force --sign - "$APP/Contents/MacOS/sing-box"
  else
    codesign --force --options runtime --timestamp --sign "$IDENTITY" "$APP/Contents/MacOS/sing-box"
  fi
else
  echo "warning: macos/engine/sing-box is missing - run macos/engine-scripts/fetch-engine.sh (the app will download the engine on first connect)"
  rm -f "$APP/Contents/MacOS/sing-box"
fi

#!/bin/sh
# Runs once as root (the app asks for the admin password through osascript)
# and again only when the engine or these scripts change.
#
#   install-helper.sh <sing-box binary> <dir with mvpn-engine> <user> <stamp>
set -eu

BIN="$1"
SRC="$2"
USER_NAME="$3"
STAMP="$4"
DST=/Library/PrivilegedHelperTools/com.mbunie.mvpn

case "$USER_NAME" in
  '' | *[!A-Za-z0-9._-]*) echo "bad user name" >&2; exit 2 ;;
esac

mkdir -p "$DST"
chown root:wheel "$DST"
chmod 755 "$DST"
install -o root -g wheel -m 755 "$BIN" "$DST/sing-box"
install -o root -g wheel -m 755 "$SRC/mvpn-engine" "$DST/mvpn-engine"
printf '%s\n' "$STAMP" > "$DST/VERSION"
chmod 644 "$DST/VERSION"

# Only the helper, only for this user. The helper is root-owned, so the user
# cannot change what it runs.
TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT
printf '%s ALL=(root) NOPASSWD: %s/mvpn-engine\n' "$USER_NAME" "$DST" > "$TMP"
visudo -cf "$TMP" >/dev/null
install -o root -g wheel -m 440 "$TMP" "/etc/sudoers.d/mvpn-$USER_NAME"

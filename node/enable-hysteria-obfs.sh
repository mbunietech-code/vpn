#!/usr/bin/env bash
# Add a second Hysteria2 listener with Salamander obfuscation on a node.
#
# Plain Hysteria2 looks like QUIC, which China's firewall slows down at peak
# times. Salamander makes every packet look like random UDP. It runs NEXT TO
# the plain listener (own port + own port-hopping range), so apps that still
# hold an old config keep working; new configs try the obfuscated one first.
#
#   ssh root@<node> 'bash -s' < node/enable-hysteria-obfs.sh
#   bash node/enable-hysteria-obfs.sh --patch-only   # install.sh: re-apply only
#
# Needs mvpn-agent >= 0.4.0 (it fills users into every Hysteria2 inbound).
# Safe to re-run: the password is kept, nothing is duplicated.
set -euo pipefail

CFG=/etc/mvpn/config
SB_CFG="$CFG/singbox-config.json"
INFO="$CFG/node-info.json"
HOP="$CFG/porthop.nft"
PW_FILE="$CFG/hysteria-obfs.pw"
OBFS_PORT="${OBFS_PORT:-8444}"
OBFS_RANGE="${OBFS_RANGE:-50000-60000}"
log(){ printf '==> %s\n' "$*"; }
err(){ printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || err "run as root"
command -v jq >/dev/null || err "jq is required"
[[ -s "$SB_CFG" && -s "$INFO" ]] || err "run install.sh first"

if [[ "${1:-}" == "--patch-only" && ! -s "$PW_FILE" ]]; then
  exit 0   # never enabled on this node
fi

[[ -s "$PW_FILE" ]] || { log "new Salamander password"; openssl rand -hex 16 > "$PW_FILE"; chmod 600 "$PW_FILE"; }
PW="$(tr -d '\n' < "$PW_FILE")"
HOP_FROM="${OBFS_RANGE%-*}"; HOP_TO="${OBFS_RANGE#*-}"

# ---- sing-box: hy2-obfs-in = hy2-in + obfs, on its own port --------------
log "sing-box: hy2-obfs-in on udp/${OBFS_PORT}"
SB_BIN="$(command -v sing-box)"
jq --arg pw "$PW" --argjson port "$OBFS_PORT" '
  (.inbounds | map(select(.tag == "hy2-in")) | first) as $plain
  | if $plain == null then error("hy2-in not found") else . end
  | .inbounds = ([.inbounds[] | select(.tag != "hy2-obfs-in")]
      + [$plain
          | .tag = "hy2-obfs-in"
          | .listen_port = $port
          | .obfs = { type: "salamander", password: $pw }
          | del(.masquerade)])   # no HTTP/3 masquerade once obfuscated
' "$SB_CFG" > "${SB_CFG%.json}.next.json"
"$SB_BIN" check -c "${SB_CFG%.json}.next.json" || { rm -f "${SB_CFG%.json}.next.json"; err "sing-box rejected the config"; }
if ! cmp -s "${SB_CFG%.json}.next.json" "$SB_CFG"; then
  cp -a "$SB_CFG" "${SB_CFG%.json}.json.bak-obfs-$(date +%Y%m%d-%H%M%S)"
  mv "${SB_CFG%.json}.next.json" "$SB_CFG"; chmod 600 "$SB_CFG"
  systemctl reload-or-restart mvpn-singbox
else
  rm -f "${SB_CFG%.json}.next.json"
fi

# ---- port hopping for the obfuscated port --------------------------------
if [[ -f "$HOP" ]] && ! grep -q "redirect to :${OBFS_PORT}" "$HOP"; then
  log "port hopping ${OBFS_RANGE} -> ${OBFS_PORT}/udp"
  sed -i "/redirect to :/a\\    udp dport ${HOP_FROM}-${HOP_TO} redirect to :${OBFS_PORT}" "$HOP"
fi
[[ -f "$HOP" ]] && nft -f "$HOP"

if command -v ufw >/dev/null; then
  ufw allow "${OBFS_PORT}/udp" >/dev/null
  ufw allow "${HOP_FROM}:${HOP_TO}/udp" >/dev/null
fi

# ---- tell the control plane (the agent sends node-info.json) -------------
jq --arg pw "$PW" --argjson port "$OBFS_PORT" --arg range "$OBFS_RANGE" \
  '.hysteria_obfs_port = $port | .hysteria_obfs_port_range = $range | .hysteria_obfs_password = $pw' \
  "$INFO" > "$INFO.next" && mv "$INFO.next" "$INFO" && chmod 600 "$INFO"

# The agent (>= 0.4.0) copies users into hy2-obfs-in on its next sync.
USERS="$(jq '[.inbounds[] | select(.tag == "hy2-obfs-in") | .users | length] | first' "$SB_CFG")"
log "done: hy2-obfs-in has ${USERS} user(s) now; mvpn-agent >= 0.4.0 keeps it in sync"

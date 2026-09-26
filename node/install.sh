#!/usr/bin/env bash
# =============================================================================
#  MVPN NODE BOOTSTRAP  (install.sh)
#  Idempotent one-shot provisioning for a single VPN node on a fresh Ubuntu
#  22.04+ VPS. Installs: Xray-core (VLESS+REALITY on tcp/443), sing-box
#  (Hysteria2 on udp/443 + port hopping), ufw, BBR tuning and the MVPN
#  node-agent.
#
#  Shares the box politely: firewall rules are only ADDED (never reset), SSH
#  hardening can be skipped, and both protocols move off 443 with --port
#  when another service (e.g. a web server) already owns it.
#
#  Usage:
#    sudo ./install.sh \
#      --domain n1.mbuniehub.com \
#      --reality-dest www.apple.com:443 \
#      --reality-sni  www.apple.com \
#      --control-plane https://vpn.mbuniehub.com \
#      --node-token   <PER_NODE_SECRET> \
#      --hysteria-port-range 20000-30000 \
#      [--port 8443] [--skip-ssh-hardening]
#
#  Re-runnable: keys, short id, Hysteria2 password/cert are preserved unless
#  --rotate is passed. Everything the control plane needs is written to
#  /etc/mvpn/node-info.json and reported by the agent automatically.
#  See 03-SDD-MVPN.md §4.4.1 and 05-Addendum-MVPN.md §A4.
# =============================================================================
set -euo pipefail

# ---------- defaults ----------------------------------------------------------
DOMAIN=""
REALITY_DEST="www.apple.com:443"
REALITY_SNI="www.apple.com"
CONTROL_PLANE=""
NODE_TOKEN=""
HYSTERIA_RANGE="20000-30000"
PORT=443                        # tcp = VLESS+REALITY, udp = Hysteria2
HARDEN_SSH=1
XRAY_VERSION=""                 # empty = latest release
SINGBOX_VERSION="1.11.15"       # keep in step with control-plane/scripts/fetch-singbox.sh
ROTATE=0
MVPN_DIR="/etc/mvpn"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log(){ printf '\033[1;34m[mvpn]\033[0m %s\n' "$*"; }
warn(){ printf '\033[1;33m[mvpn:warn]\033[0m %s\n' "$*" >&2; }
err(){ printf '\033[1;31m[mvpn:err]\033[0m %s\n' "$*" >&2; exit 1; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2;;
    --reality-dest) REALITY_DEST="$2"; shift 2;;
    --reality-sni) REALITY_SNI="$2"; shift 2;;
    --control-plane) CONTROL_PLANE="${2%/}"; shift 2;;
    --node-token) NODE_TOKEN="$2"; shift 2;;
    --hysteria-port-range) HYSTERIA_RANGE="$2"; shift 2;;
    --port) PORT="$2"; shift 2;;
    --skip-ssh-hardening) HARDEN_SSH=0; shift;;
    --xray-version) XRAY_VERSION="$2"; shift 2;;
    --singbox-version) SINGBOX_VERSION="$2"; shift 2;;
    --rotate) ROTATE=1; shift;;
    *) err "unknown arg: $1";;
  esac
done

[[ $EUID -eq 0 ]] || err "run as root (sudo)"
[[ -n "$DOMAIN" ]] || err "--domain required"
[[ -n "$CONTROL_PLANE" ]] || err "--control-plane required"
[[ -n "$NODE_TOKEN" ]] || err "--node-token required"
[[ "$HYSTERIA_RANGE" =~ ^[0-9]+-[0-9]+$ ]] || err "--hysteria-port-range must look like 20000-30000"
HOP_FROM="${HYSTERIA_RANGE%-*}"
HOP_TO="${HYSTERIA_RANGE#*-}"
[[ "$PORT" =~ ^[0-9]+$ ]] || err "--port must be a number"

# Refuse to fight another service (web server, docker-proxy…) for our port.
port_owner(){ ss -Hlpn "$1" "sport = :$PORT" | grep -vE 'xray|sing-box' | grep -o 'users:(("[^"]*' | head -1 | cut -d'"' -f2; }
for proto in -t -u; do
  owner="$(port_owner "$proto" || true)"
  [[ -z "$owner" ]] || err "port $PORT ($proto) is already used by '$owner' - pick another with --port"
done

export DEBIAN_FRONTEND=noninteractive
mkdir -p "$MVPN_DIR"/{config,tls,bin}
chmod 700 "$MVPN_DIR/config" "$MVPN_DIR/tls"

# ---------- 1. base packages ------------------------------------------------
log "updating base system"
apt-get update -y -q
apt-get install -y -q curl jq ufw unzip ca-certificates nftables openssl

# ---------- 2. sshd hardening (key-only) ------------------------------------
# Ubuntu 24.04+ images (Vultr/Hostinger cloud-init) ship
# sshd_config.d/50-cloud-init.conf with "PasswordAuthentication yes"; sshd
# takes the FIRST value it reads, so our drop-in must sort before it.
# Never disable passwords unless root already has a key - that locks you out.
if [[ $HARDEN_SSH -eq 0 ]]; then
  log "leaving SSH settings untouched (--skip-ssh-hardening)"
elif [[ -s /root/.ssh/authorized_keys ]] && grep -qE '^(ssh-|ecdsa-)' /root/.ssh/authorized_keys; then
  log "hardening sshd (key-only)"
  mkdir -p /etc/ssh/sshd_config.d
  cat > /etc/ssh/sshd_config.d/00-mvpn.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
EOF
  if sshd -t; then
    systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
  else
    rm -f /etc/ssh/sshd_config.d/00-mvpn.conf
    warn "sshd config test failed - left SSH settings unchanged"
  fi
else
  warn "no SSH key in /root/.ssh/authorized_keys - password login LEFT ENABLED."
  warn "add your key, then re-run this script to switch to key-only SSH."
fi

# ---------- 3. kernel tuning (BBR + UDP buffers for Hysteria2) ---------------
log "tuning kernel (BBR, fq, UDP buffers)"
cat > /etc/sysctl.d/99-mvpn.conf <<'EOF'
net.core.default_qdisc = fq
net.ipv4.tcp_congestion_control = bbr
net.core.rmem_max = 16777216
net.core.wmem_max = 16777216
net.ipv4.tcp_fastopen = 3
net.ipv4.tcp_mtu_probing = 1
fs.file-max = 1048576
EOF
sysctl --system >/dev/null || warn "some sysctl keys were rejected (container VPS?)"

# ---------- 4. firewall + Hysteria2 port hopping -----------------------------
# Only ADD rules - never reset: other services' rules must survive.
log "configuring ufw"
ufw allow 22/tcp >/dev/null
ufw allow "${PORT}/tcp" >/dev/null
ufw allow "${PORT}/udp" >/dev/null
ufw allow "${HOP_FROM}:${HOP_TO}/udp" >/dev/null
if ! ufw status | grep -q '^Status: active'; then
  ufw default deny incoming >/dev/null
  ufw default allow outgoing >/dev/null
  ufw --force enable
fi

# Clients get mport=<range>; every port in it is redirected to hy2 on :$PORT.
# Separate nft table so ufw reload never touches it.
log "installing Hysteria2 port-hopping redirect ${HYSTERIA_RANGE} -> ${PORT}/udp"
cat > "$MVPN_DIR/config/porthop.nft" <<EOF
table inet mvpn_hop {}
delete table inet mvpn_hop
table inet mvpn_hop {
  chain prerouting {
    type nat hook prerouting priority dstnat; policy accept;
    udp dport ${HOP_FROM}-${HOP_TO} redirect to :${PORT}
  }
}
EOF
cat > /etc/systemd/system/mvpn-porthop.service <<EOF
[Unit]
Description=MVPN Hysteria2 port hopping (nftables redirect)
After=network-pre.target
Wants=network-pre.target
[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/nft -f ${MVPN_DIR}/config/porthop.nft
ExecStop=/usr/sbin/nft delete table inet mvpn_hop
[Install]
WantedBy=multi-user.target
EOF

# ---------- 5. Xray-core ---------------------------------------------------
if ! command -v xray >/dev/null || [[ $ROTATE -eq 1 ]]; then
  log "installing Xray-core (${XRAY_VERSION:-latest})"
  if [[ -n "$XRAY_VERSION" ]]; then
    bash -c "$(curl -fsSL https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" @ install --version "$XRAY_VERSION"
  else
    bash -c "$(curl -fsSL https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" @ install
  fi
fi
# The upstream installer ships its own xray.service on the default config;
# we run our own unit instead.
systemctl disable --now xray >/dev/null 2>&1 || true

# `xray x25519` output changed across releases:
#   old:  "Private key: X" / "Public key: Y"
#   new:  "PrivateKey: X"  / "Password: Y"  (+ "Hash32: Z")
# We store only the private key and always derive the public key from it.
kv(){ awk -F': *' -v re="$1" 'tolower($1) ~ re { print $NF; exit }'; }
REALITY_PRIV_FILE="$MVPN_DIR/config/reality.key"
if [[ ! -s "$REALITY_PRIV_FILE" || $ROTATE -eq 1 ]]; then
  log "generating REALITY keypair"
  xray x25519 | kv '^private ?key' > "$REALITY_PRIV_FILE"
  chmod 600 "$REALITY_PRIV_FILE"
fi
REALITY_PRIV="$(cat "$REALITY_PRIV_FILE")"
REALITY_PUB="$(xray x25519 -i "$REALITY_PRIV" | kv '^(public ?key|password)')"
[[ -n "$REALITY_PRIV" && -n "$REALITY_PUB" ]] || err "could not parse 'xray x25519' output"

SHORT_ID_FILE="$MVPN_DIR/config/reality.sid"
if [[ ! -s "$SHORT_ID_FILE" || $ROTATE -eq 1 ]]; then
  openssl rand -hex 8 > "$SHORT_ID_FILE"
fi
SHORT_ID="$(cat "$SHORT_ID_FILE")"

# ---------- 6. sing-box (Hysteria2) --------------------------------------
if ! command -v sing-box >/dev/null || [[ $ROTATE -eq 1 ]] \
   || [[ "$(sing-box version 2>/dev/null | awk 'NR==1{print $3}')" != "$SINGBOX_VERSION" ]]; then
  log "installing sing-box $SINGBOX_VERSION"
  bash -c "$(curl -fsSL https://sing-box.app/install.sh)" -- --version "$SINGBOX_VERSION"
fi
systemctl disable --now sing-box >/dev/null 2>&1 || true
SINGBOX_BIN="$(command -v sing-box)"
XRAY_BIN="$(command -v xray)"

HY2_PW_FILE="$MVPN_DIR/config/hysteria.pw"
[[ -s "$HY2_PW_FILE" && $ROTATE -eq 0 ]] || openssl rand -base64 24 > "$HY2_PW_FILE"

# Self-signed cert for Hysteria2. Clients pin it (SHA-256 / PEM from the
# subscription), so it needs a SAN - Go rejects CN-only certificates.
HY2_CRT="$MVPN_DIR/tls/hy2.crt"
HY2_KEY="$MVPN_DIR/tls/hy2.key"
if [[ ! -s "$HY2_CRT" || $ROTATE -eq 1 ]]; then
  log "generating Hysteria2 certificate"
  openssl req -x509 -nodes -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 \
    -keyout "$HY2_KEY" -out "$HY2_CRT" -days 3650 \
    -subj "/CN=${REALITY_SNI}" -addext "subjectAltName=DNS:${REALITY_SNI}" 2>/dev/null
  chmod 600 "$HY2_KEY"
fi
HY2_SHA256="$(openssl x509 -in "$HY2_CRT" -noout -fingerprint -sha256 | cut -d= -f2)"

# ---------- 7. render engine configs ----------------------------------
# Client lists start empty; the agent fills them from the control plane.
# Existing configs are re-rendered (settings may have changed) but keep
# their current clients/users so a re-run never drops paying users.
keep_json(){ [[ -s "$1" ]] && jq -c "$2" "$1" 2>/dev/null || echo '[]'; }
XRAY_CFG="$MVPN_DIR/config/xray-config.json"
SB_CFG="$MVPN_DIR/config/singbox-config.json"
XRAY_CLIENTS="$(keep_json "$XRAY_CFG" '.inbounds[] | select(.tag=="vless-reality") | .settings.clients // []')"
SB_USERS="$(keep_json "$SB_CFG" '.inbounds[0].users // []')"

log "rendering Xray config"
jq -n \
  --arg dest "$REALITY_DEST" --arg sni "$REALITY_SNI" \
  --arg priv "$REALITY_PRIV" --arg sid "$SHORT_ID" --argjson port "$PORT" \
  --argjson clients "$XRAY_CLIENTS" '
{
  log: { loglevel: "warning", access: "none", dnsLog: false },
  api: { tag: "api", listen: "127.0.0.1:10085", services: ["HandlerService", "StatsService"] },
  stats: {},
  policy: {
    levels: { "0": { statsUserUplink: true, statsUserDownlink: true } },
    system: { statsInboundUplink: true, statsInboundDownlink: true }
  },
  inbounds: [{
    tag: "vless-reality",
    listen: "0.0.0.0",
    port: $port,
    protocol: "vless",
    settings: { clients: $clients, decryption: "none" },
    streamSettings: {
      network: "tcp",
      security: "reality",
      realitySettings: {
        show: false,
        dest: $dest,
        serverNames: [$sni],
        privateKey: $priv,
        shortIds: [$sid]
      }
    },
    sniffing: { enabled: true, destOverride: ["http", "tls", "quic"], routeOnly: true }
  }],
  outbounds: [
    { protocol: "freedom", tag: "direct" },
    { protocol: "blackhole", tag: "block" }
  ],
  routing: {
    rules: [ { type: "field", ip: ["geoip:private"], outboundTag: "block" } ]
  }
}' > "${XRAY_CFG%.json}.next.json"
"$XRAY_BIN" run -test -config "${XRAY_CFG%.json}.next.json" >/dev/null || err "generated Xray config failed validation"
mv "${XRAY_CFG%.json}.next.json" "$XRAY_CFG"; chmod 600 "$XRAY_CFG"

log "rendering sing-box config"
jq -n \
  --arg sni "$REALITY_SNI" --arg crt "$HY2_CRT" --arg key "$HY2_KEY" --argjson port "$PORT" \
  --argjson users "$SB_USERS" '
{
  log: { level: "warn", timestamp: true },
  inbounds: [{
    type: "hysteria2",
    tag: "hy2-in",
    listen: "::",
    listen_port: $port,
    users: $users,
    masquerade: ("https://" + $sni),
    tls: { enabled: true, alpn: ["h3"], certificate_path: $crt, key_path: $key }
  }],
  outbounds: [{ type: "direct", tag: "direct" }]
}' > "${SB_CFG%.json}.next.json"
"$SINGBOX_BIN" check -c "${SB_CFG%.json}.next.json" || err "generated sing-box config failed validation"
mv "${SB_CFG%.json}.next.json" "$SB_CFG"; chmod 600 "$SB_CFG"

# ---------- 8. node-info.json (read by the agent, sent to the control plane) --
jq -n \
  --arg host "$DOMAIN" --arg pub "$REALITY_PUB" --arg sid "$SHORT_ID" \
  --arg sni "$REALITY_SNI" --arg range "$HYSTERIA_RANGE" \
  --arg fp "$HY2_SHA256" --rawfile pem "$HY2_CRT" --argjson port "$PORT" '
{
  public_host: $host,
  reality_port: $port,
  hysteria_port: $port,
  reality_pubkey: $pub,
  reality_short_id: $sid,
  reality_sni: $sni,
  hysteria_port_range: $range,
  hysteria_cert_sha256: $fp,
  hysteria_cert_pem: $pem
}' > "$MVPN_DIR/config/node-info.json"
chmod 600 "$MVPN_DIR/config/node-info.json"

# ---------- 9. systemd units -----------------------------------------
cat > /etc/systemd/system/mvpn-xray.service <<EOF
[Unit]
Description=MVPN Xray (VLESS+REALITY)
After=network-online.target
Wants=network-online.target
[Service]
ExecStart=${XRAY_BIN} run -config ${XRAY_CFG}
Restart=on-failure
RestartSec=3
LimitNOFILE=1048576
[Install]
WantedBy=multi-user.target
EOF

# sing-box reloads its config on SIGHUP without dropping the process.
cat > /etc/systemd/system/mvpn-singbox.service <<EOF
[Unit]
Description=MVPN sing-box (Hysteria2)
After=network-online.target mvpn-porthop.service
Wants=network-online.target
[Service]
ExecStart=${SINGBOX_BIN} run -c ${SB_CFG}
ExecReload=/bin/kill -HUP \$MAINPID
Restart=on-failure
RestartSec=3
LimitNOFILE=1048576
[Install]
WantedBy=multi-user.target
EOF

# ---------- 10. node-agent -------------------------------------------
AGENT_SRC="$SCRIPT_DIR/node-agent"
if [[ ! -x "$AGENT_SRC/mvpn-agent" && -f "$AGENT_SRC/go.mod" ]]; then
  log "building node-agent from source"
  command -v go >/dev/null || apt-get install -y -q golang-go
  (cd "$AGENT_SRC" && CGO_ENABLED=0 go build -trimpath -ldflags='-s -w' -o mvpn-agent .)
fi
[[ -x "$AGENT_SRC/mvpn-agent" ]] || err "node-agent binary missing and could not be built ($AGENT_SRC)"
install -m 0755 "$AGENT_SRC/mvpn-agent" "$MVPN_DIR/bin/mvpn-agent"

cat > "$MVPN_DIR/config/agent.env" <<EOF
MVPN_CONTROL_PLANE=${CONTROL_PLANE}
MVPN_NODE_TOKEN=${NODE_TOKEN}
MVPN_DOMAIN=${DOMAIN}
MVPN_NODE_INFO=${MVPN_DIR}/config/node-info.json
MVPN_XRAY_CONFIG=${XRAY_CFG}
MVPN_SINGBOX_CONFIG=${SB_CFG}
MVPN_XRAY_BIN=${XRAY_BIN}
MVPN_SINGBOX_BIN=${SINGBOX_BIN}
MVPN_XRAY_API=127.0.0.1:10085
EOF
chmod 600 "$MVPN_DIR/config/agent.env"

cat > /etc/systemd/system/mvpn-agent.service <<EOF
[Unit]
Description=MVPN Node Agent
After=network-online.target mvpn-xray.service mvpn-singbox.service
Wants=network-online.target
[Service]
EnvironmentFile=${MVPN_DIR}/config/agent.env
ExecStart=${MVPN_DIR}/bin/mvpn-agent
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable mvpn-porthop mvpn-xray mvpn-singbox mvpn-agent >/dev/null
systemctl restart mvpn-porthop mvpn-xray mvpn-singbox mvpn-agent

# ---------- 11. self-test ------------------------------------------
log "self-test"
sleep 3
for u in mvpn-porthop mvpn-xray mvpn-singbox mvpn-agent; do
  systemctl is-active --quiet "$u" && log "$u: up" || err "$u failed - journalctl -u $u -n 50"
done
ss -Htlnp "sport = :$PORT" | grep -q xray && log "tcp/$PORT listening (REALITY)" || err "xray is not on tcp/$PORT"
ss -Hulnp "sport = :$PORT" | grep -q sing-box && log "udp/$PORT listening (Hysteria2)" || err "sing-box is not on udp/$PORT"
nft list table inet mvpn_hop >/dev/null && log "port hopping ${HYSTERIA_RANGE} -> $PORT active"

cat <<EOF

============================================================
 MVPN node ready: ${DOMAIN}
 Port               : tcp/${PORT} REALITY, udp/${PORT} Hysteria2
 REALITY public key : ${REALITY_PUB}
 REALITY short id   : ${SHORT_ID}
 REALITY SNI / dest : ${REALITY_SNI} / ${REALITY_DEST}
 Hysteria2 SHA-256  : ${HY2_SHA256}
 Hysteria2 hopping  : udp ${HYSTERIA_RANGE}

 The agent reports these values to ${CONTROL_PLANE} on its first
 health check - in the admin panel you only need a Node row with
 this node token. Watch it:  journalctl -u mvpn-agent -f
============================================================
EOF

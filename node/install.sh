#!/usr/bin/env bash
# =============================================================================
#  MVPN NODE BOOTSTRAP  (install.sh)
#  Idempotent one-shot provisioning for a single VPN node on a fresh Ubuntu
#  22.04/24.04 VPS. Installs: Xray-core (VLESS+REALITY), sing-box (Hysteria2),
#  Caddy (camouflage site), ufw, and the MVPN node-agent.
#
#  Usage:
#    sudo ./install.sh \
#      --domain node1.mbunievpn.com \
#      --reality-dest www.microsoft.com:443 \
#      --reality-sni  www.microsoft.com \
#      --control-plane https://cp.mbunievpn.com \
#      --node-token   <PER_NODE_SECRET> \
#      --hysteria-port-range 20000-30000
#
#  Re-runnable: existing keys/config are preserved unless --rotate is passed.
#  See 03-SDD-MVPN.md §4.4.1 and 05-Addendum-MVPN.md §A4.
# =============================================================================
set -euo pipefail

# ---------- defaults ----------------------------------------------------------
DOMAIN=""
REALITY_DEST="www.microsoft.com:443"
REALITY_SNI="www.microsoft.com"
CONTROL_PLANE=""
NODE_TOKEN=""
HYSTERIA_RANGE="20000-30000"
XRAY_VERSION="latest"
SINGBOX_VERSION="latest"
ROTATE=0
MVPN_DIR="/etc/mvpn"

log(){ printf '\033[1;34m[mvpn]\033[0m %s\n' "$*"; }
err(){ printf '\033[1;31m[mvpn:err]\033[0m %s\n' "$*" >&2; exit 1; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2;;
    --reality-dest) REALITY_DEST="$2"; shift 2;;
    --reality-sni) REALITY_SNI="$2"; shift 2;;
    --control-plane) CONTROL_PLANE="$2"; shift 2;;
    --node-token) NODE_TOKEN="$2"; shift 2;;
    --hysteria-port-range) HYSTERIA_RANGE="$2"; shift 2;;
    --rotate) ROTATE=1; shift;;
    *) err "unknown arg: $1";;
  esac
done

[[ $EUID -eq 0 ]] || err "run as root (sudo)"
[[ -n "$DOMAIN" ]] || err "--domain required"
[[ -n "$CONTROL_PLANE" ]] || err "--control-plane required"
[[ -n "$NODE_TOKEN" ]] || err "--node-token required"

export DEBIAN_FRONTEND=noninteractive
mkdir -p "$MVPN_DIR"/{config,scripts,tls,www,bin}

# ---------- 1. base packages + hardening ------------------------------------
log "updating base system"
apt-get update -y -q
apt-get install -y -q curl jq ufw unzip ca-certificates nftables

log "hardening sshd (key-only)"
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
systemctl reload ssh || systemctl reload sshd || true

# ---------- 2. firewall -----------------------------------------------------
log "configuring ufw"
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 443/tcp
ufw allow 443/udp
ufw allow "${HYSTERIA_RANGE//-/:}"/udp
ufw --force enable

# ---------- 3. Xray-core ---------------------------------------------------
if ! command -v xray >/dev/null || [[ $ROTATE -eq 1 ]]; then
  log "installing Xray-core ($XRAY_VERSION)"
  bash -c "$(curl -fsSL https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" @ install
fi

REALITY_KEYS_FILE="$MVPN_DIR/config/reality.keys"
if [[ ! -f "$REALITY_KEYS_FILE" || $ROTATE -eq 1 ]]; then
  log "generating REALITY keypair"
  xray x25519 > "$REALITY_KEYS_FILE"
  chmod 600 "$REALITY_KEYS_FILE"
fi
REALITY_PRIV=$(awk '/Private key/{print $3}' "$REALITY_KEYS_FILE")
REALITY_PUB=$(awk '/Public key/{print $3}' "$REALITY_KEYS_FILE")
SHORT_ID=$(openssl rand -hex 8)

# ---------- 4. sing-box (Hysteria2) --------------------------------------
if ! command -v sing-box >/dev/null || [[ $ROTATE -eq 1 ]]; then
  log "installing sing-box"
  bash -c "$(curl -fsSL https://sing-box.app/install.sh)"
fi
HY2_PW_FILE="$MVPN_DIR/config/hysteria.pw"
[[ -f "$HY2_PW_FILE" && $ROTATE -eq 0 ]] || openssl rand -base64 24 > "$HY2_PW_FILE"

# ---------- 5. Caddy camouflage site -----------------------------------
if ! command -v caddy >/dev/null; then
  log "installing Caddy"
  apt-get install -y -q debian-keyring debian-archive-keyring apt-transport-https
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -y -q && apt-get install -y -q caddy
fi
[[ -f "$MVPN_DIR/www/index.html" ]] || cat > "$MVPN_DIR/www/index.html" <<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kesho Logistics</title><style>body{font:16px/1.6 system-ui;margin:0;color:#1a2233}
.wrap{max-width:720px;margin:8vh auto;padding:0 24px}h1{font-size:28px}</style></head>
<body><div class="wrap"><h1>Kesho Logistics</h1>
<p>Regional freight forwarding and customs brokerage since 2016. Our team coordinates
sea, air and road shipments across East Africa and Asia-Pacific trade lanes.</p>
<p>For a quote, email <a href="mailto:hello@example.com">hello@example.com</a>.</p>
</div></body></html>
HTML

# Caddy serves the site on 443 for any request Xray's fallback hands back.
cat > /etc/caddy/Caddyfile <<EOF
{
  auto_https disable_redirects
}
http://127.0.0.1:8080, https://${DOMAIN}:8080 {
  root * ${MVPN_DIR}/www
  file_server
}
EOF
systemctl enable --now caddy
systemctl reload caddy || systemctl restart caddy

# ---------- 6. render engine configs ----------------------------------
log "rendering Xray config"
cat > "$MVPN_DIR/config/xray-config.json" <<EOF
{
  "log": { "loglevel": "warning", "access": "none", "dnsLog": false },
  "inbounds": [{
    "tag": "vless-reality",
    "listen": "0.0.0.0",
    "port": 443,
    "protocol": "vless",
    "settings": { "clients": [], "decryption": "none",
      "fallbacks": [{ "dest": "127.0.0.1:8080" }] },
    "streamSettings": {
      "network": "tcp",
      "security": "reality",
      "realitySettings": {
        "show": false,
        "dest": "${REALITY_DEST}",
        "serverNames": ["${REALITY_SNI}"],
        "privateKey": "${REALITY_PRIV}",
        "shortIds": ["${SHORT_ID}"]
      }
    },
    "sniffing": { "enabled": true, "destOverride": ["http","tls","quic"], "routeOnly": true }
  }],
  "outbounds": [{ "protocol": "freedom", "tag": "direct" }],
  "policy": { "levels": { "0": { "statsUserUplink": true, "statsUserDownlink": true } },
              "system": { "statsInboundUplink": true, "statsInboundDownlink": true } },
  "stats": {},
  "api": { "tag": "api", "services": ["HandlerService","StatsService"] }
}
EOF

log "rendering sing-box config"
cat > "$MVPN_DIR/config/singbox-config.json" <<EOF
{
  "log": { "level": "warn", "timestamp": true },
  "inbounds": [{
    "type": "hysteria2",
    "tag": "hy2-in",
    "listen": "::",
    "listen_port": 443,
    "users": [],
    "masquerade": "https://${REALITY_SNI}",
    "tls": {
      "enabled": true,
      "alpn": ["h3"],
      "certificate_path": "${MVPN_DIR}/tls/${DOMAIN}.crt",
      "key_path": "${MVPN_DIR}/tls/${DOMAIN}.key"
    }
  }],
  "outbounds": [{ "type": "direct", "tag": "direct" }]
}
EOF

# self-signed cert for Hysteria2 (client uses pinned SHA256 from subscription)
if [[ ! -f "$MVPN_DIR/tls/${DOMAIN}.crt" || $ROTATE -eq 1 ]]; then
  openssl req -x509 -nodes -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 \
    -keyout "$MVPN_DIR/tls/${DOMAIN}.key" -out "$MVPN_DIR/tls/${DOMAIN}.crt" \
    -subj "/CN=${REALITY_SNI}" -days 3650
fi

# ---------- 7. systemd units -----------------------------------------
cat > /etc/systemd/system/mvpn-xray.service <<EOF
[Unit]
Description=MVPN Xray
After=network.target
[Service]
ExecStart=/usr/local/bin/xray run -config ${MVPN_DIR}/config/xray-config.json
Restart=on-failure
RestartSec=3
LimitNOFILE=1048576
[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/mvpn-singbox.service <<EOF
[Unit]
Description=MVPN sing-box (Hysteria2)
After=network.target
[Service]
ExecStart=/usr/bin/sing-box run -c ${MVPN_DIR}/config/singbox-config.json
Restart=on-failure
RestartSec=3
LimitNOFILE=1048576
[Install]
WantedBy=multi-user.target
EOF

# ---------- 8. node-agent -------------------------------------------
log "installing node-agent"
install -m 0755 "$(dirname "$0")/node-agent/mvpn-agent" "$MVPN_DIR/bin/mvpn-agent" 2>/dev/null || \
  log "node-agent binary not bundled - build it from ./node/node-agent and re-run"

cat > "$MVPN_DIR/config/agent.env" <<EOF
MVPN_CONTROL_PLANE=${CONTROL_PLANE}
MVPN_NODE_TOKEN=${NODE_TOKEN}
MVPN_DOMAIN=${DOMAIN}
MVPN_REALITY_PUBKEY=${REALITY_PUB}
MVPN_REALITY_SHORTID=${SHORT_ID}
MVPN_REALITY_SNI=${REALITY_SNI}
MVPN_HYSTERIA_PORT_RANGE=${HYSTERIA_RANGE}
MVPN_XRAY_CONFIG=${MVPN_DIR}/config/xray-config.json
MVPN_SINGBOX_CONFIG=${MVPN_DIR}/config/singbox-config.json
MVPN_XRAY_API=127.0.0.1:10085
EOF
chmod 600 "$MVPN_DIR/config/agent.env"

cat > /etc/systemd/system/mvpn-agent.service <<EOF
[Unit]
Description=MVPN Node Agent
After=network.target mvpn-xray.service
[Service]
EnvironmentFile=${MVPN_DIR}/config/agent.env
ExecStart=${MVPN_DIR}/bin/mvpn-agent
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now mvpn-xray mvpn-singbox
systemctl enable --now mvpn-agent || log "agent not started (binary missing)"

# ---------- 9. self-test ------------------------------------------
log "self-test"
sleep 2
systemctl is-active --quiet mvpn-xray   && log "xray: up"    || err "xray failed"
systemctl is-active --quiet mvpn-singbox && log "singbox: up" || err "singbox failed"
curl -sk "https://${DOMAIN}:8080" -o /dev/null -w "camouflage site: HTTP %{http_code}\n" || true

cat <<EOF

============================================================
 MVPN node ready: ${DOMAIN}
 REALITY public key : ${REALITY_PUB}
 REALITY short id   : ${SHORT_ID}
 REALITY SNI / dest : ${REALITY_SNI} / ${REALITY_DEST}
 Register this node in the control plane with the values above.
 The node-agent will pull peers automatically once registered.
============================================================
EOF

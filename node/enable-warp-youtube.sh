#!/usr/bin/env bash
# Route YouTube through Cloudflare WARP on a node.
#
# YouTube treats the node's data-centre IPs as bots: videos answer
# LOGIN_REQUIRED ("Sign in to confirm you're not a bot"). Through WARP they
# play. Only YouTube's domains go that way; everything else stays direct.
#
#   ssh root@<node> 'bash -s' < node/enable-warp-youtube.sh
#   bash node/enable-warp-youtube.sh --patch-only   # install.sh: rules only
#
# WARP runs in PROXY mode only (SOCKS on 127.0.0.1:40000): it never touches
# the server's routes, so LiveKit and SSH are unaffected. Proxy mode is set
# BEFORE connecting - the default "warp" mode would take over all routing.
# QUIC (UDP) to YouTube is rejected so apps fall back to TCP through WARP.
set -euo pipefail

CFG=/etc/mvpn/config
PORT=40000
YT='["youtube.com","googlevideo.com","ytimg.com","youtu.be","youtube-nocookie.com","youtubei.googleapis.com","youtube.googleapis.com","ggpht.com","yt.be","youtubekids.com","withyoutube.com"]'
log(){ printf '==> %s\n' "$*"; }

if [[ "${1:-}" != "--patch-only" ]]; then
  if ! command -v warp-cli >/dev/null; then
    . /etc/os-release
    log "installing cloudflare-warp ($VERSION_CODENAME)"
    curl -fsSL https://pkg.cloudflareclient.com/pubkey.gpg | gpg --yes --dearmor -o /usr/share/keyrings/cloudflare-warp-archive-keyring.gpg
    echo "deb [signed-by=/usr/share/keyrings/cloudflare-warp-archive-keyring.gpg] https://pkg.cloudflareclient.com/ $VERSION_CODENAME main" \
      > /etc/apt/sources.list.d/cloudflare-client.list
    DEBIAN_FRONTEND=noninteractive apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq cloudflare-warp
    sleep 3
  fi
  warp-cli --accept-tos registration show >/dev/null 2>&1 || warp-cli --accept-tos registration new
  warp-cli --accept-tos mode proxy
  warp-cli --accept-tos proxy port "$PORT"
  warp-cli --accept-tos connect
  sleep 5
  curl -s -m 15 --socks5-hostname "127.0.0.1:$PORT" https://www.cloudflare.com/cdn-cgi/trace | grep -q '^warp=on' \
    || { echo "WARP proxy is not working - leaving the engines unchanged" >&2; exit 1; }
  log "WARP proxy up on 127.0.0.1:$PORT"
fi

ss -ltn | grep -q "127.0.0.1:$PORT " || { echo "no WARP proxy on 127.0.0.1:$PORT" >&2; exit 1; }

# Idempotent: drop earlier warp bits, then put the YouTube rules first.
XRAY="$CFG/xray-config.json"
jq --argjson yt "$YT" --argjson port "$PORT" '
  .outbounds = ([.outbounds[] | select(.tag != "warp")] + [{protocol:"socks",tag:"warp",settings:{servers:[{address:"127.0.0.1",port:$port}]}}])
  | .routing.rules = ([
      {type:"field",network:"udp",domain:($yt|map("domain:"+.)),outboundTag:"block"},
      {type:"field",domain:($yt|map("domain:"+.)),outboundTag:"warp"}
    ] + [.routing.rules[] | select(.outboundTag != "warp" and ((.network // "") != "udp" or (.domain // [] | length) == 0))])
' "$XRAY" > "${XRAY%.json}.warp.json"
xray run -test -config "${XRAY%.json}.warp.json" >/dev/null
mv "${XRAY%.json}.warp.json" "$XRAY"; chmod 600 "$XRAY"

SB="$CFG/singbox-config.json"
jq --argjson yt "$YT" --argjson port "$PORT" '
  .outbounds = ([.outbounds[] | select(.tag != "warp")] + [{type:"socks",tag:"warp",server:"127.0.0.1",server_port:$port,version:"5"}])
  | .route = {rules:[{domain_suffix:$yt,network:["udp"],action:"reject"},{domain_suffix:$yt,outbound:"warp"}],final:"direct"}
' "$SB" > "${SB%.json}.warp.json"
sing-box check -c "${SB%.json}.warp.json"
mv "${SB%.json}.warp.json" "$SB"; chmod 600 "$SB"
log "YouTube rules written"

if [[ "${1:-}" != "--patch-only" ]]; then
  systemctl restart mvpn-xray
  systemctl reload-or-restart mvpn-singbox
  log "engines reloaded: xray $(systemctl is-active mvpn-xray), sing-box $(systemctl is-active mvpn-singbox)"
fi

#!/usr/bin/env bash
# Mbunie VPS security check. Read-only: it never changes, stops or deletes anything.
#
#   mbunie-security-check            full check (daily timer)
#   mbunie-security-check --quick    keys, users, cron, processes, logins (hourly timer)
#   mbunie-security-check --accept   take today's state as the known-good baseline
#   mbunie-security-check --print    print only, do not report
#
# Problems are sent to the control plane (POST /api/node/security with the node
# token from /etc/mvpn/config/agent.env). They show on vpn.mbuniehub.com →
# Alerts ("Server security") and are emailed / sent to Telegram once while open.
set -uo pipefail
export LC_ALL=C PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

MODE=full; ACCEPT=0; PRINT=0
for a in "$@"; do
  case "$a" in
    --quick) MODE=quick ;;
    --accept) ACCEPT=1 ;;
    --print) PRINT=1 ;;
  esac
done

STATE=/var/lib/mbunie-security
LOG=/var/log/mbunie-security.log
ENV_FILE=/etc/mvpn/config/agent.env
# --print is a dry run: nothing is written on the server (baseline in a temp folder).
[ "$PRINT" = 1 ] && { STATE=$(mktemp -d); LOG=/dev/null; }
mkdir -p "$STATE"; chmod 700 "$STATE"
FINDINGS=$(mktemp); CUR=$(mktemp -d)
trap 'rm -rf "$FINDINGS" "$CUR"' EXIT

add() { # level title detail
  jq -cn --arg l "$1" --arg t "$2" --arg d "${3:-}" '{level:$l,title:$t,detail:($d|.[0:1900])}' >>"$FINDINGS"
}

# Compare a list with its baseline: new lines are reported, the first run saves it.
#   compare <name> <level-for-new> <title-for-new> [level-for-removed title-for-removed]
compare() {
  local name=$1 lvl=$2 title=$3 rlvl=${4:-} rtitle=${5:-}
  sort -u -o "$CUR/$name" "$CUR/$name"
  if [ ! -f "$STATE/$name" ] || [ "$ACCEPT" = 1 ]; then
    cp "$CUR/$name" "$STATE/$name"; return
  fi
  comm -13 "$STATE/$name" "$CUR/$name" | head -20 | while IFS= read -r line; do
    [ -n "$line" ] && add "$lvl" "$title" "$line"
  done
  if [ -n "$rlvl" ]; then
    comm -23 "$STATE/$name" "$CUR/$name" | head -20 | while IFS= read -r line; do
      [ -n "$line" ] && add "$rlvl" "$rtitle" "$line"
    done
  fi
}

# ---------------------------------------------------------------- SSH
sshd_conf=$(sshd -T 2>/dev/null)
if grep -qi '^passwordauthentication yes' <<<"$sshd_conf"; then
  add critical "SSH accepts passwords" "Password guessing can break in. Use keys only: PasswordAuthentication no (check /etc/ssh/sshd_config.d/*.conf)."
fi
if grep -qi '^permitrootlogin yes' <<<"$sshd_conf"; then
  add warn "Root can log in with a password" "Set PermitRootLogin prohibit-password."
fi

: >"$CUR/ssh_keys"
for f in /root/.ssh/authorized_keys /root/.ssh/authorized_keys2 /home/*/.ssh/authorized_keys; do
  [ -f "$f" ] || continue
  ssh-keygen -lf "$f" 2>/dev/null | awk -v f="$f" '{c=""; for(i=3;i<NF;i++) c=c $i " "; print f": "$2" "c}' >>"$CUR/ssh_keys"
done
compare ssh_keys critical "New SSH key can log in" info "SSH key removed"

# ------------------------------------------------------- users and sudo
awk -F: '$3==0 && $1!="root"{print $1}' /etc/passwd | while read -r u; do
  add critical "Second root account (uid 0)" "$u"
done
awk -F: '$7 !~ /(nologin|false|sync|halt|shutdown)$/ {print $1" "$7}' /etc/passwd >"$CUR/login_users"
compare login_users critical "New user that can log in"
{ getent group sudo admin wheel 2>/dev/null; ls /etc/sudoers.d 2>/dev/null; md5sum /etc/sudoers 2>/dev/null; } >"$CUR/sudo"
compare sudo critical "Admin (sudo) rights changed" warn "Admin (sudo) entry removed"

# --------------------------------------------------------- cron and services
{ cat /etc/crontab 2>/dev/null; for f in /etc/cron.d/* /var/spool/cron/crontabs/*; do [ -f "$f" ] && sed "s|^|$f: |" "$f"; done; } \
  | grep -v ':\s*#' | grep -v ': *$' | grep -v '^#' | grep -v '^\s*$' >"$CUR/cron"
compare cron critical "New cron job" warn "Cron job removed"
ls /etc/systemd/system/*.service /etc/systemd/system/*.timer /etc/systemd/system/*/*.service 2>/dev/null >"$CUR/units"
compare units warn "New system service" info "System service removed"
[ -e /etc/ld.so.preload ] && add critical "/etc/ld.so.preload exists (used by rootkits)" "$(head -c 300 /etc/ld.so.preload)"

# ------------------------------------------------------------ processes
ps -eo pid=,pcpu=,etimes=,args= | while read -r pid cpu secs cmd; do
  if grep -qiE 'xmrig|minerd|cpuminer|kdevtmpfsi|kinsing|stratum\+tcp' <<<"$cmd"; then
    add critical "Miner running" "PID $pid: ${cmd:0:200}"
  fi
  exe=$(readlink "/proc/$pid/exe" 2>/dev/null)
  case "$exe" in
    /tmp/*|/var/tmp/*|/dev/shm/*) add critical "Program running from a temp folder" "PID $pid $exe: ${cmd:0:160}" ;;
  esac
  if [ "${cpu%.*}" -ge 90 ] 2>/dev/null && [ "$secs" -ge 1800 ] && ! grep -qE 'livekit|turnserver|sing-box|xray|openvpn|caddy|warp-svc|dpkg|apt|unattended' <<<"$cmd"; then
    add warn "Program using a lot of CPU for a long time" "PID $pid ${cpu}% for $((secs/60)) min: ${cmd:0:160}"
  fi
done
find /tmp /var/tmp /dev/shm -xdev -type f -perm /111 2>/dev/null | head -10 | while read -r f; do
  add critical "Program file in a temp folder" "$f"
done

# ------------------------------------------------------- network exposure
if ! ufw status 2>/dev/null | grep -q 'Status: active'; then
  add critical "Firewall (ufw) is off" "Everything listening is open to the internet."
fi
ufw status 2>/dev/null | grep -E 'ALLOW|DENY|LIMIT' >"$CUR/ufw"
compare ufw warn "Firewall rule added" warn "Firewall rule removed"
# Listeners reachable from outside (loopback skipped; random UDP ports of the VPN engines skipped).
ss -tulpnH 2>/dev/null | awk '{
  split($7, p, "\""); proc=p[2]; addr=$5; n=split(addr, a, ":"); port=a[n]; host=substr(addr, 1, length(addr)-length(port)-1);
  if (host ~ /^(127\.|\[::1\]|::1)/) next;
  if ($1=="udp" && port>=32768 && proc ~ /sing-box|xray|turnserver/) next;
  print $1"/"port" "proc
}' | sort -u >"$CUR/listen"
compare listen warn "New open port"

# ------------------------------------------------------------- logins
journalctl -u ssh -u sshd --since "-25 hours" --no-pager 2>/dev/null >"$CUR/sshlog"
fails=$(grep -cE 'Failed password|Invalid user' "$CUR/sshlog")
[ "${fails:-0}" -gt 0 ] && add info "Failed SSH logins in the last 24 h" "$fails attempts; top: $(grep -E 'Failed password|Invalid user' "$CUR/sshlog" | grep -oE 'from [0-9a-f.:]+' | sort | uniq -c | sort -rn | head -3 | tr -s ' ' | tr '\n' ';')"
grep -oE 'Accepted [a-z-]+ for [a-z0-9_-]+ from [0-9a-f.:]+' "$CUR/sshlog" | awk '{print $4" "$6}' | sort -u >"$CUR/login_ips"
# First run (or --accept): today's login addresses are the known ones.
if [ ! -f "$STATE/known_login_ips" ] || [ "$ACCEPT" = 1 ]; then cp "$CUR/login_ips" "$STATE/known_login_ips"; fi
comm -13 <(sort -u "$STATE/known_login_ips") "$CUR/login_ips" | while read -r who ip; do
  [ -n "$ip" ] && add warn "SSH login from a new IP address" "$who from $ip (if this was not you or the team, change keys/passwords now)"
done
sort -u "$STATE/known_login_ips" "$CUR/login_ips" -o "$STATE/known_login_ips"
grep -q 'Accepted password' "$CUR/sshlog" && \
  add critical "Someone logged in with a password" "$(grep 'Accepted password' "$CUR/sshlog" | tail -3 | cut -c1-300)"
systemctl is-active --quiet fail2ban || add warn "fail2ban is not running" "Repeated SSH password guesses are not blocked."

# --------------------------------------------------------------- docker
if command -v docker >/dev/null; then
  docker ps -a --format '{{.Names}} {{.Image}}' 2>/dev/null >"$CUR/containers"
  compare containers warn "New docker container" info "Docker container removed"
  for c in live-server-livekit-1 live-server-caddy-1 live-server-coturn-1; do
    [ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null)" = "true" ] || add warn "Live class container not running" "$c"
  done
fi

# ------------------------------------------------- full check only (slower)
if [ "$MODE" = full ]; then
  dpkg --verify 2>/dev/null | awk '$2!="c" && $1 ~ /5/ {print $NF}' | grep -E '^/(usr/)?(s?bin|lib)' | head -20 | while read -r f; do
    add critical "System program changed (not by an update)" "$f"
  done
  sec=$(apt list --upgradable 2>/dev/null | grep -ci security)
  [ "${sec:-0}" -gt 0 ] && add warn "Security updates waiting" "$sec package(s); unattended-upgrades should install them tonight."
  systemctl is-enabled --quiet unattended-upgrades 2>/dev/null || add warn "Automatic security updates are off" "Enable unattended-upgrades."
  if [ -f /var/run/reboot-required ]; then
    age=$(( ( $(date +%s) - $(stat -c %Y /var/run/reboot-required) ) / 86400 ))
    [ "$age" -ge 7 ] && add warn "Reboot needed for security updates" "Waiting for $age days ($(tr '\n' ' ' </var/run/reboot-required.pkgs 2>/dev/null | cut -c1-200)). Plan a quiet hour: live classes and VPN drop for ~1 minute."
  fi
  use=$(df --output=pcent / | tail -1 | tr -dc 0-9)
  [ "${use:-0}" -ge 90 ] && add warn "Disk almost full" "/ is ${use}% used"
fi

# ------------------------------------------------------------- report
count=$(grep -c . "$FINDINGS")
echo "[$(date -u '+%F %T')] $MODE check: $count finding(s)$( [ "$ACCEPT" = 1 ] && echo ', baseline accepted')" >>"$LOG"
jq -r '"  [\(.level)] \(.title): \(.detail)"' "$FINDINGS" | tee -a "$LOG"
[ "$count" = 0 ] && echo "Nothing suspicious found."

if [ "$PRINT" = 0 ] && [ -r "$ENV_FILE" ]; then
  CP=$(grep -E '^MVPN_CONTROL_PLANE=' "$ENV_FILE" | cut -d= -f2-)
  TOKEN=$(grep -E '^MVPN_NODE_TOKEN=' "$ENV_FILE" | cut -d= -f2-)
  if [ -n "$CP" ] && [ -n "$TOKEN" ]; then
    body=$(jq -cs --arg h "$(hostname)" --arg m "$MODE" '{host:$h, mode:$m, findings:.}' "$FINDINGS")
    code=$(curl -sS -o "$CUR/resp" -w '%{http_code}' --max-time 30 -X POST "$CP/api/node/security" \
      -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' --data "$body")
    echo "  reported to control plane: HTTP $code $(head -c 200 "$CUR/resp")" | tee -a "$LOG"
  fi
fi

jq -e 'select(.level=="critical")' "$FINDINGS" >/dev/null 2>&1 && exit 2
exit 0

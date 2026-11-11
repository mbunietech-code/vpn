#!/usr/bin/env bash
# Installs the daily VPS security check (run as root on the node, from this folder).
#   bash node/security/install.sh
# Adds: /usr/local/sbin/mbunie-security-check, a daily full check (04:40 UTC)
# and an hourly quick check. The first run saves today's state as the baseline.
# It changes nothing else: no service is stopped or restarted.
set -euo pipefail
cd "$(dirname "$0")"

command -v jq >/dev/null || apt-get install -y jq

install -m 0750 mbunie-security-check.sh /usr/local/sbin/mbunie-security-check

cat >/etc/systemd/system/mbunie-security-check.service <<'EOF'
[Unit]
Description=Mbunie security check (full)
After=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/mbunie-security-check
SuccessExitStatus=2
Nice=15
IOSchedulingClass=idle
EOF

cat >/etc/systemd/system/mbunie-security-check.timer <<'EOF'
[Unit]
Description=Daily Mbunie security check

[Timer]
OnCalendar=*-*-* 04:40:00 UTC
RandomizedDelaySec=10m
Persistent=true

[Install]
WantedBy=timers.target
EOF

cat >/etc/systemd/system/mbunie-security-check-quick.service <<'EOF'
[Unit]
Description=Mbunie security check (quick)
After=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/mbunie-security-check --quick
SuccessExitStatus=2
Nice=15
IOSchedulingClass=idle
EOF

cat >/etc/systemd/system/mbunie-security-check-quick.timer <<'EOF'
[Unit]
Description=Hourly Mbunie security check (quick)

[Timer]
OnCalendar=*-*-* *:23:00
Persistent=true

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now mbunie-security-check.timer mbunie-security-check-quick.timer

# First run: saves the baseline and reports what is wrong today.
/usr/local/sbin/mbunie-security-check || true
systemctl list-timers --no-pager | grep mbunie-security

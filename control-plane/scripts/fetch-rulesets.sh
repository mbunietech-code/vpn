#!/usr/bin/env bash
# Refreshes the China rule sets the client configs point at:
#   https://vpn.mbuniehub.com/rules/geosite-cn.srs
#   https://vpn.mbuniehub.com/rules/geoip-cn.srs
# (see SubscriptionBuilder::chinaRuleSets). Run locally, commit, deploy.
#   bash control-plane/scripts/fetch-rulesets.sh
set -euo pipefail

HERE="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$HERE/public/rules"
mkdir -p "$OUT"

fetch() { # <repo> <file>
  echo "==> $2"
  curl -fsSL "https://raw.githubusercontent.com/SagerNet/$1/rule-set/$2" -o "$OUT/$2.next"
  [[ -s "$OUT/$2.next" ]] || { echo "empty download: $2" >&2; exit 1; }
  mv "$OUT/$2.next" "$OUT/$2"
}

fetch sing-geosite geosite-cn.srs
fetch sing-geoip   geoip-cn.srs

ls -la "$OUT"

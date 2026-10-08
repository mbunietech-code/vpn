#!/usr/bin/env bash
# Rotate the shared OpenVPN client credentials on a node (e.g. after a leak).
#
#   ssh root@<node> 'bash -s' < node/rotate-openvpn-client.sh
#
# - backs up /etc/openvpn/server and the easy-rsa PKI
# - revokes the current client cert, issues a new one, enables the CRL
# - generates a new tls-crypt key (instantly locks out every old profile)
# - restarts the three mbunie OpenVPN servers (LiveKit/Caddy untouched)
# - writes fresh client profiles to /root/mvpn-ovpn/ (paste the tcp443 one
#   into Admin → Nodes → OpenVPN config)
# Prints no key material.
set -euo pipefail

PKI_DIR=/etc/openvpn/mbunie-easy-rsa
SRV=/etc/openvpn/server
HOST=${MVPN_OVPN_HOST:-n1.mbuniehub.com}
OUT=/root/mvpn-ovpn
TS=$(date +%Y%m%d-%H%M%S)

mkdir -p "/root/openvpn-backup-$TS"
cp -a "$SRV" "/root/openvpn-backup-$TS/server"
cp -a "$PKI_DIR" "/root/openvpn-backup-$TS/easy-rsa"
echo "==> backup: /root/openvpn-backup-$TS"

cd "$PKI_DIR"
export EASYRSA_BATCH=1

# Revoke every currently valid client cert (anything that isn't the server).
OLD=$(awk -F'\t' '$1=="V"{sub(/.*CN=/,"",$6); print $6}' pki/index.txt | grep -v -x "$HOST" || true)
for cn in $OLD; do
  ./easyrsa revoke "$cn" >/dev/null 2>&1 && echo "==> revoked $cn"
done

NEW="mbunie-client-$TS"
./easyrsa build-client-full "$NEW" nopass >/dev/null 2>&1
echo "==> issued $NEW"

./easyrsa gen-crl >/dev/null 2>&1
install -m 644 pki/crl.pem "$SRV/crl.pem"

openvpn --genkey secret "$SRV/ta.key.new"
chmod 600 "$SRV/ta.key.new"
mv "$SRV/ta.key.new" "$SRV/ta.key"
echo "==> new tls-crypt key"

for f in "$SRV"/mbunie*.conf; do
  grep -q '^crl-verify' "$f" || echo 'crl-verify crl.pem' >> "$f"
done

for u in mbunie mbunie-udp mbunie443; do
  systemctl restart "openvpn-server@$u"
  printf '==> openvpn-server@%s: %s\n' "$u" "$(systemctl is-active "openvpn-server@$u")"
done

mkdir -p "$OUT"
chmod 700 "$OUT"
profile() { # $1=name $2=proto $3=port
  {
    cat <<EOF
client
dev tun
proto $2
remote $HOST $3
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
cipher AES-256-GCM
data-ciphers AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305
auth SHA256
verb 3
EOF
    echo '<ca>';        cat pki/ca.crt;                                  echo '</ca>'
    echo '<cert>';      openssl x509 -in "pki/issued/$NEW.crt";          echo '</cert>'
    echo '<key>';       cat "pki/private/$NEW.key";                      echo '</key>'
    echo '<tls-crypt>'; cat "$SRV/ta.key";                               echo '</tls-crypt>'
  } > "$OUT/$1.ovpn"
  chmod 600 "$OUT/$1.ovpn"
}
profile mbunie-tcp443  tcp-client 443
profile mbunie-tcp1194 tcp-client 1194
profile mbunie-udp1194 udp        1194
echo "==> profiles: $(ls "$OUT")"
echo "Next: paste $OUT/mbunie-tcp443.ovpn into Admin → Nodes → OpenVPN config."

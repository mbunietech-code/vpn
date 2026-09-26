# Node deployment

A **node** is one VPS running the obfuscated VPN endpoints + the MVPN agent.
Nodes hold no customer data — only opaque peer credentials.

| Protocol | Port | Engine |
|---|---|---|
| VLESS + REALITY (Vision) | tcp/443 | Xray (`mvpn-xray`) |
| Hysteria2 | udp/443 + hopping range (default udp/20000-30000 → 443) | sing-box (`mvpn-singbox`) |

## 0. The VPS

Any Ubuntu 22.04+ KVM VPS with a decent route to mainland China
(Tokyo / Hong Kong / Seoul / Kuala Lumpur / Singapore). 1 vCPU + 2 GB is plenty
for the first few hundred users.

- Put **your SSH key** on it first (`/root/.ssh/authorized_keys`).
  `install.sh` only switches SSH to key-only when a key is present.
- If the provider has its own firewall panel (Hostinger/Vultr), open
  **tcp/22, tcp/443, udp/443, udp/20000-30000** there as well.
- DNS: `A n1.mbuniehub.com → <VPS IP>` in Cloudflare, **DNS only (grey)**.

> IP blocked from China later? Rebuild the VPS (fresh IP), re-run steps 1–2,
> set the old node to `disabled`.

## 1. Create the node in the admin panel

Admin → **Nodes → New**: name, region, `public_host` (`n1.mbuniehub.com`).
A **node token** is generated for you — copy it. Leave status `provisioning`.

## 2. Run the bootstrap on the VPS

```bash
ssh root@<vps-ip>
apt-get update && apt-get install -y git
git clone https://github.com/mbunietech-code/vpn.git /opt/mvpn   # or scp the node/ folder
cd /opt/mvpn/node
sudo ./install.sh \
  --domain n1.mbuniehub.com \
  --reality-dest www.apple.com:443 \
  --reality-sni  www.apple.com \
  --control-plane https://vpn.mbuniehub.com \
  --node-token   <TOKEN FROM STEP 1> \
  --hysteria-port-range 20000-30000
```

The script builds the agent from source if no binary is present
(`golang-go` from apt), installs Xray + sing-box (pinned, see
`--xray-version` / `--singbox-version`), tunes the kernel (BBR), sets ufw +
the nftables port-hopping redirect, renders and **validates** both engine
configs, and starts `mvpn-porthop`, `mvpn-xray`, `mvpn-singbox`, `mvpn-agent`.

**REALITY `dest` / `sni`** must be a site that is (a) fully reachable inside
China, (b) high-traffic, (c) TLS 1.3 + HTTP/2. Verified with Xray 26:
`www.apple.com`, `swdist.apple.com`, `www.bing.com`. **Not `www.microsoft.com`**
— with Xray 26 every REALITY handshake against it fails ("handshake did not
complete successfully"). Check a candidate first: `xray tls ping <host>`.
**Never** use anything blocked in China (Google, YouTube, Wikipedia, …).

## 3. Nothing to copy back

On its first health report the agent sends `/etc/mvpn/config/node-info.json`
(REALITY public key + short id + SNI, Hysteria2 port range + certificate).
The control plane stores them, flips the node `online`, and **provisions peers
for every already-active subscription**. Within ~15 s the agent applies them.

If you ever need to force it: `php artisan mvpn:sync-peers` on the control plane.

## 4. Verify

```bash
systemctl status mvpn-porthop mvpn-xray mvpn-singbox mvpn-agent
journalctl -u mvpn-agent -f               # "applied peer list version N (M active peers)"
nft list table inet mvpn_hop              # port hopping rule
ss -tulpn | grep ':443'
```

Then import a real `/sub/{token}` into stock Hiddify on an unrestricted
network, then repeat from inside China for the 24–72 h field test.

## Operations

- **Re-run is safe:** keys, short id, Hysteria2 cert and current users are kept.
- **Rotate keys:** `sudo ./install.sh --rotate …` — the agent reports the new
  values; clients pick them up on their next subscription refresh.
- **Maintenance:** set the node to `draining` or `disabled` — it drops out of
  every subscription and agent health won't flip it back to `online`.
- **Agent can't apply a config:** it keeps the last good one and raises a
  `node.sync` alert with the engine's error text.

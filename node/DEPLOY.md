# Node deployment

A **node** is one VPS running the obfuscated VPN endpoints + the MVPN agent.
Nodes hold no customer data — only opaque peer credentials.

## 0. Provision the VPS  —  Vultr, Tokyo

All users are inside mainland China, so the node must have a good China-egress
route. Chosen: **Vultr High Frequency, Tokyo**.

1. Vultr → **Deploy** → **Cloud Compute – High Frequency**.
2. Location: **Tokyo**. (Seoul is a fine alternative; Singapore is weaker for CN.)
3. OS: **Ubuntu 24.04 LTS x64**.
4. Plan: **2 GB RAM / 1 vCPU** (~$12/mo). 1 GB works but is tight once several
   users are on.
5. Add your **SSH key** during creation (so password login is off from minute one).
6. Enable **IPv6** (free). Auto-backups optional.
7. Label: `mvpn-node-tokyo`. Deploy.

After it boots, note the IPv4. Point a DNS **A record** `tk1.<yourdomain>` at it.
Put the domain on **Cloudflare**; keep this record **grey-cloud (DNS only)** for
now — the orange-cloud CDN front is added later (FR-CN-03).

> If this specific IP turns out to be blocked from China, just **destroy +
> redeploy** in Vultr — you get a fresh IP in a minute, then re-run steps 2–4.

## 1. Build the agent

No Go toolchain on the dev machine — build on the VPS or in CI:

```bash
# on the VPS (after apt-get install -y golang), from a checkout of this repo:
cd node/node-agent
CGO_ENABLED=0 go build -o mvpn-agent .
cp mvpn-agent ../   # so install.sh can pick it up at ./node-agent/mvpn-agent
```

or cross-compile anywhere:

```bash
cd node/node-agent && GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -o mvpn-agent .
scp mvpn-agent root@hk1.mbunievpn.com:/root/node/node-agent/
```

## 2. Run the bootstrap

Copy the `node/` folder to the VPS, then:

```bash
sudo ./install.sh \
  --domain tk1.mbunievpn.com \
  --reality-dest www.apple.com:443 \
  --reality-sni  www.apple.com \
  --control-plane https://cp.mbunievpn.com \
  --node-token   "$(openssl rand -hex 24)" \
  --hysteria-port-range 20000-30000
```

**REALITY `dest` / `sni`** must be a site that is (a) fully reachable inside
China, (b) high-traffic, (c) TLS 1.3 + HTTP/2, (d) has a CDN presence in Japan.
Good: `www.apple.com`, `www.microsoft.com`, `www.bing.com`, `swdist.apple.com`.
**Never** use anything blocked in China (Google, YouTube, Wikipedia, etc.).

Save the `--node-token` value and the REALITY public key / short id / SNI it
prints at the end.

## 3. Register the node in the control plane

Admin panel → **Nodes → New**, or:

```bash
php artisan tinker --execute="\App\Models\Node::create([
  'name' => 'Hong Kong 1', 'region' => 'hk',
  'public_host' => 'hk1.mbunievpn.com',
  'api_base' => 'https://hk1.mbunievpn.com',
  'api_secret' => 'THE_NODE_TOKEN_FROM_STEP_2',
  'reality_pubkey' => '...', 'reality_short_id' => '...',
  'reality_sni' => 'www.microsoft.com',
  'hysteria_port_range' => '20000-30000',
  'hysteria_cert_sha256' => '...:...',
  'status' => 'online',
]);"
```

The agent polls `GET /api/node/peers` every 15 s and applies the peer list;
health is posted every 60 s. New paid subscriptions appear within ~15 s.

## 4. Verify

```bash
systemctl status mvpn-xray mvpn-singbox mvpn-agent
curl -sk https://hk1.mbunievpn.com:8080        # camouflage site
journalctl -u mvpn-agent -f                    # watch peer syncs
```

Then import a real `/sub/{token}` into a stock sing-box / Hiddify client on an
unrestricted network and confirm traffic flows; repeat from inside China for
the 24–72 h field test (SDD §9).

## Rotation / incident response

- Rotate REALITY keys: `sudo ./install.sh --rotate ...` then update the node
  row; clients pick up the change on their next subscription refresh.
- IP blocked: stand up a fresh VPS, run `install.sh`, register, set the old
  node `status = draining` then `offline`.

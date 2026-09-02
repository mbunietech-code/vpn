# MVPN Launch Runbook

Ordered checklist to get from zero to a working paid connection.
Users are all inside mainland China. Node = Vultr Tokyo. Control plane = Hostinger NL.

---

## Step 1 — Buy infrastructure  (~30 min, ~$25–35/mo + $10/yr)

| # | What | Where | Choice |
|---|---|---|---|
| 1.1 | **Domain** | Porkbun / Namecheap / Cloudflare Registrar | any `.com`, e.g. `mbunievpn.com` |
| 1.2 | **Cloudflare** account | cloudflare.com | Free plan; add the domain, switch nameservers |
| 1.3 | **Node VPS** | **Vultr** → Cloud Compute – High Frequency | **Tokyo**, Ubuntu 24.04, **2 GB RAM**, add SSH key |
| 1.4 | **Control-plane VPS** | **Hostinger** (the tab you have open) | **Netherlands**, Ubuntu 24.04, KVM 2 |
| 1.5 | **Stripe** account | stripe.com | start onboarding — enable Alipay + WeChat Pay + Card |
| 1.6 | **Cryptomus** account | cryptomus.com | start onboarding (USDT) |

SSH key: on Windows run `ssh-keygen -t ed25519` in Git Bash, paste
`~/.ssh/id_ed25519.pub` into Vultr and Hostinger.

## Step 2 — DNS  (Cloudflare dashboard)

| Type | Name | Value | Proxy |
|---|---|---|---|
| A | `cp` | Hostinger VPS IP | 🟠 Proxied (so China can reach it) |
| A | `tk1` | Vultr Tokyo IP | ⚪ DNS only (grey) |

## Step 3 — Control plane  (Hostinger VPS)

Follow **`control-plane/DEPLOY.md`**. Summary:
```bash
ssh root@<hostinger-ip>
# create sudo user, disable root+password SSH
apt update && apt install -y php8.3-{cli,fpm,mbstring,xml,curl,mysql,redis,bcmath,gd,zip,intl} \
                             mysql-server redis-server caddy git
# clone repo, composer install --no-dev, .env (mysql + stripe + cryptomus + anthropic keys)
php artisan migrate --force
php artisan db:seed --class=MvpnSeeder --force
# Caddy: reverse-proxy cp.mbunievpn.com -> 127.0.0.1:8000 (php artisan serve OR php-fpm)
# systemd: mvpn-queue (queue:work), mvpn-scheduler (schedule:work)
```
Log in at `https://cp.mbunievpn.com/admin`, **change the admin password**, set
CNY/USD prices on each plan.

## Step 4 — Node  (Vultr Tokyo)

Follow **`node/DEPLOY.md`**. Summary:
```bash
ssh root@<vultr-ip>
apt update && apt install -y golang git
git clone <repo> && cd <repo>/node/node-agent && CGO_ENABLED=0 go build -o mvpn-agent . && cp mvpn-agent ../
cd .. && sudo ./install.sh \
  --domain tk1.mbunievpn.com \
  --reality-dest www.apple.com:443 --reality-sni www.apple.com \
  --control-plane https://cp.mbunievpn.com \
  --node-token "$(openssl rand -hex 24)" \
  --hysteria-port-range 20000-30000
```
Copy the **node token** + **REALITY public key / short id / SNI** it prints.

## Step 5 — Register the node

Admin panel → **Nodes → New**: name `Tokyo 1`, region `tk`, public host
`tk1.mbunievpn.com`, paste the token + REALITY values, status `online`.
Within ~15 s the agent starts syncing peers.

## Step 6 — Payment webhooks

- Stripe dashboard → Developers → Webhooks → add
  `https://cp.mbunievpn.com/webhooks/stripe`
  (events: `checkout.session.completed`, `*.async_payment_succeeded`,
  `*.async_payment_failed`, `charge.refunded`). Copy the signing secret → `.env`.
- Cryptomus → settings → callback URL `https://cp.mbunievpn.com/webhooks/cryptomus`.

## Step 7 — First real end-to-end test  (from a normal, non-China network)

1. Build the app: `flutter build apk --release --dart-define=MVPN_API=https://cp.mbunievpn.com`
2. Install on a phone, register, buy the cheapest plan with a Stripe **test** card.
3. Confirm the app auto-activates and connects; browse for a few minutes.
4. Import the same `/sub/{token}` into stock **Hiddify** to double-check the node.

## Step 8 — China field test

Give a build to 1–2 trusted users already in China. Watch for 24–72 h:
stability, speed at peak hours, any disconnects. Keep a spare Vultr node image
ready. Only advertise after this passes.

## Step 9 — Go live (soft launch)

Crypto + Alipay first, Android first. Add WeChat Pay / iOS / desktop as they
clear review. Watch the AI alert feed + Telegram.

---

### Monthly cost estimate
Vultr Tokyo 2 GB ≈ $12 · Hostinger NL KVM2 ≈ $8–10 · domain ≈ $1 · Cloudflare $0
· Stripe 3.4%+ / Cryptomus ~1% per transaction. **≈ $22–25/mo fixed.**

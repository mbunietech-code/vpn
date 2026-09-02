# Mbunie VPN (MVPN) — monorepo

Commercial, censorship-resistant VPN. Pay → **auto-activate** (no admin
confirmation). Primary target network: China / GFW. Clients: Flutter on
Android, Windows, macOS, Linux.

See the spec set: `01`–`04` (original), **`05-Addendum`** (current commercial
scope — authoritative), `06-Design-System`.

## Layout

| Path | What | Stack | Status |
|---|---|---|---|
| `control-plane/` | Backend API + admin panel. Users, plans, subscriptions, payments, provisioning, AI alerts. | Laravel 13 + Filament 4 + MySQL (SQLite for local dev) | **scaffolded, core flow tested** |
| `node/install.sh` | One-shot node bootstrap: Xray VLESS+REALITY, sing-box Hysteria2, Caddy camouflage, ufw. | Bash | drafted, needs a VPS to run |
| `node/node-agent/` | Per-node daemon: pulls peer list from control plane, renders configs, hot-reloads engines, reports health. | Go 1.23 | drafted |
| `client/` | Flutter app. Auth (OTP) → plans (¥/$) → pay → poll → auto-connect. | Flutter 3.44; sing-box libbox planned (Hiddify-Next fork) | **UI + backend wired; tunnel still simulated** |

## Control plane — local dev

```bash
cd control-plane
composer install
php artisan migrate:fresh --seed      # SQLite; admin@mbunievpn.com / change-me-now
php artisan serve                     # http://localhost:8000
php artisan queue:work                # provisioning jobs
php artisan schedule:work             # expiry sweep + anomaly scan
```

Admin panel: `http://localhost:8000/admin`
API base: `http://localhost:8000/api`

### Auto-activation flow (implemented)
`POST /api/checkout` → provider-hosted pay page → `POST /webhooks/{stripe|cryptomus}`
(signature + amount verified) → `ProvisionSubscriptionJob` → subscription `active`
+ peers on every eligible node → app polls `GET /api/subscription` → imports
`GET /sub/{token}` → connects.

## What still needs the owner
- [ ] Node VPS (HK/JP/SG, China-optimised line) + control-plane VPS + domain(s) + Cloudflare.
- [ ] Stripe onboarding (card + Alipay + WeChat Pay) and Cryptomus account — start now, approval takes days.
- [ ] Stitch screens delivered as screenshots (Home, Servers, Session Stats, Settings, Auth, Plans, Checkout).

## Guardrails (non-negotiable — see 04 §3 + 05 §A9)
No content logging · no plaintext client credentials · key-only SSH · kill-switch fails closed ·
standard OSS components only · no raw card/bank data in MVPN code (provider-hosted checkout only).

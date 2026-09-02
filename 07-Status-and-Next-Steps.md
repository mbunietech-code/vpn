# MVPN — Build Status & Next Steps

**Updated:** 2026-09-02
**Repo:** monorepo at project root (git initialised)

---

## Done in this session

### Specs
- **`05-Addendum-MVPN.md`** — authoritative commercial scope: auto-activation on payment, Stripe (Alipay/WeChat) + Cryptomus, dual CNY/USD pricing, Laravel control-plane + admin, AI anomaly alerting, China/GFW hardening, Flutter × 4 platforms.
- **`06-Design-System-MVPN.md`** — colour tokens + component + screen spec from the Stitch mockups.

### `control-plane/` (Laravel 13 + Filament 4) — **working, tested**
- Full schema: users, otp_codes, plans, nodes, invoices, webhook_events, subscriptions, devices, peers, alerts, audit_logs.
- **Auto-activation pipeline** (no admin confirmation):
  `POST /api/checkout` → provider checkout → `POST /webhooks/{stripe|cryptomus}` (signature + amount + currency verified, idempotent) → `ProvisionSubscriptionJob` → subscription `active` + `vless-reality` & `hysteria2` peers on every eligible node → app polls `GET /api/subscription` → `GET /sub/{token}` bundle.
- Payment driver layer: `PaymentGateway` interface + `StripeGateway` + `CryptomusGateway` (test keys — live keys pending merchant approval).
- Passwordless auth: `POST /api/auth/otp/request|verify` (Sanctum tokens). OTP currently logged / returned in local; SMS+email senders are TODO.
- Node-agent API: `GET /api/node/peers` (node pulls authoritative list by version), `POST /api/node/health`.
- `mvpn:sweep-expired` (5-min schedule) — disables peers on lapse, re-enables on renewal.
- `mvpn:scan-anomalies` (1-min schedule) — rule-based checks + optional Claude API severity/summary + Telegram push for critical.
- Filament admin resources generated for all 8 models. Admin: `admin@mbunievpn.com` / `change-me-now` (change immediately).
- Feature tests green: auto-activation, node peers endpoint, expiry sweep.
- **Local dev DB = SQLite** (WAMP MySQL service needs admin rights to start). Production VPS: set `DB_CONNECTION=mysql`.

### `node/` — drafted, needs a VPS to exercise
- `install.sh` — idempotent bootstrap: SSH hardening, ufw, Xray-core (VLESS+REALITY:443), sing-box (Hysteria2), Caddy camouflage site, systemd units, agent env.
- `node-agent/` (Go 1.23) — pulls peer list every 15s, patches Xray/sing-box client lists, hot-reloads, posts health every 60s. `go build` not yet run (no Go toolchain here) — build on the node or in CI.

### `client/` (Flutter 3.44) — UI + real backend integration
- Theme: light + dark from the design tokens.
- Screens: **Auth** (phone/email → OTP), **Plans** (¥/$ toggle, live from `/api/plans`), **Checkout** (provider pick → hosted pay page → poll), Home, Servers, Session Stats, Settings.
- `AppState` + `ApiClient` (package:http): OTP login (Sanctum token persisted), plan load, checkout, subscription polling, auto-activation gate (`needsAuth → needsPlan → ready`).
- `SubParser`: `/sub/{token}` bundle → node list feeding the connect UI.
- **Verified end-to-end** (local): OTP → `dev/pay` → active subscription + 2 peers → `/sub/{token}` → `/api/node/peers`.
- Tests: `dart analyze` clean, 3 tests green (widget + SubParser). Windows .exe builds & runs.
- **The tunnel itself is still simulated** (`VpnController`) — real sing-box engine is the Hiddify-Next fork work (Phase 5).
- `--dart-define=MVPN_API=https://cp.mbunievpn.com` to point at a real control plane.

---

## Next steps (in order)

### Owner — do now (blocks everything downstream)
1. **VPS ×2**: one node (Hong Kong / Japan / Singapore, China-optimised line — CN2 GIA / IPLC), one control-plane (EU/US, reputable). Ubuntu 24.04.
2. **Domain(s)** + Cloudflare account (for the CDN front, FR-CN-03).
3. **Stripe** business onboarding (enable card + Alipay + WeChat Pay) and **Cryptomus** account. Both take days — start today.
4. Change the seeded admin password; set real plan prices in the admin panel.

### Engineering — continue
5. Deploy control-plane to its VPS — see **`control-plane/DEPLOY.md`** (MySQL, Redis, Horizon, `schedule:work`, `queue:work`, TLS).
6. `go build` the node-agent + run `install.sh` + register the node — see **`node/DEPLOY.md`**.
7. Wire real SMS (Africa's Talking or Twilio) + email OTP senders (replace the `logger()->info` in `AuthController`).
8. Register webhooks with Stripe + Cryptomus; end-to-end test with test keys → real webhook → provisioning → import `/sub/{token}` into a stock Hiddify client → connect through the node.
9. Fork **Hiddify-Next**, rebrand from `06-Design-System`, drop in the MVPN `AppState` flow (auth + plans + pay + poll already built here), lock protocol templates, keep kill-switch / DNS guard / fallback. Build Android → Windows → macOS → Linux.
10. Second node + CDN front + 24–72 h field test from inside China.

### CI
`.github/workflows/ci.yml` runs control-plane tests, Flutter analyze+test+APK, and the Go agent build on every push.

### Soft-launch recommendation
Crypto-only payment + Android-only, once step 8 + Android build are done and one node is field-tested. Add Stripe/Alipay + desktop as they clear.

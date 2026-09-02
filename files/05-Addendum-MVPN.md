# ADDENDUM 1 — Commercial / Auto-Activation Scope
## Mbunie VPN (MVPN)

**Document Version:** 1.0
**Date:** 2026-09-02
**Status:** Approved scope change — supersedes conflicting parts of 01–04
**Read with:** `01-Concept-Note`, `02-SRS`, `03-SDD`, `04-Build-Instructions`

---

## A0. Why this addendum exists

Docs 01–04 specify MVPN as a **personal, self-hosted** VPN with **manual, script-based** peer administration; "payment processing / self-service signup" is explicitly **out of scope (SRS §1.2)**.

The product owner has changed the scope. MVPN v1.0 is now a **small commercial paid service**:

1. **Automatic activation on payment** — a user pays and gains access with **no admin confirmation of the payment**.
2. **Must connect reliably from inside China (GFW)** — while most paying users are in Tanzania / Africa.
3. **Laravel admin control-plane** — manage the entire app (users, plans, subscriptions, nodes) from one panel, with **AI anomaly alerting**.
4. **Client is a Flutter app** for **Android, Windows, macOS, Linux** (no iOS in v1).
5. **Payments:** Stripe (Alipay + WeChat Pay) and Cryptomus / NowPayments (crypto). **Prices displayed in both CNY (¥) and USD ($).**

Where this addendum conflicts with 01–04, **this addendum wins**. All non-negotiable guardrails in `04 §3` (no content logging, no plaintext credentials, key-only SSH, kill-switch fails closed, standard open-source components only) **still apply unchanged**.

---

## A1. Revised system architecture

```
┌───────────────────────────────────────────────────────────┐
│  MVPN CONTROL PLANE   — Laravel 11 + MySQL 8 + Redis        │
│  Host: dedicated VPS (SEPARATE from all VPN nodes)          │
│                                                            │
│  Public API (app-facing, HTTPS):                            │
│    POST /api/auth/register        (phone/email + OTP)       │
│    POST /api/auth/login                                     │
│    GET  /api/plans                (prices in CNY + USD)     │
│    POST /api/checkout             → returns pay URL         │
│    GET  /api/subscription         → status + sub token      │
│    GET  /sub/{opaque_token}       → sing-box subscription   │
│                                                            │
│  Webhooks (provider-facing, signature-verified):           │
│    POST /webhooks/stripe                                    │
│    POST /webhooks/cryptomus  (or /webhooks/nowpayments)    │
│                                                            │
│  Internal node API (mTLS or per-node bearer token):        │
│    node-agent  ⇄  control plane  (peer sync, health, stats) │
│                                                            │
│  Admin panel (Filament): users, subscriptions, invoices,   │
│    nodes, peers, revocations, AI alert feed, audit log      │
│                                                            │
│  Queue workers: provisioning jobs, webhook processing,     │
│    expiry sweeper, AI anomaly analysis                      │
└───────────────┬───────────────────────────────────────────┘
                │  HTTPS, signed  (control plane → node pull/push)
                ▼
┌───────────────────────────────────────────────────────────┐
│  MVPN NODE  (one per VPS, region outside GFW)               │
│                                                            │
│  node-agent  (small Go daemon, systemd):                    │
│    • long-poll / webhook from control plane                 │
│    • apply peer add/disable/remove                          │
│    • re-render xray-config.json + singbox-config.json       │
│    • hot-reload engines (Xray API / sing-box restart)       │
│    • push health + per-peer byte counters back              │
│                                                            │
│  Xray-core     : VLESS + REALITY on TCP 443  (primary)      │
│  sing-box      : Hysteria2 + port-hopping    (fallback)     │
│  Caddy         : real camouflage website on 443 fallback    │
│  ufw/nftables  : only 22 (key-only), 443/tcp, hop range/udp │
└───────────────────────────────────────────────────────────┘
                ▲   VLESS+REALITY  /  Hysteria2
┌───────────────────────────────────────────────────────────┐
│  MVPN FLUTTER APP   Android · Windows · macOS · Linux       │
│  Base: fork of Hiddify-Next (Flutter + sing-box libbox FFI) │
│                                                            │
│    onboarding → plans (¥ / $) → pay (Stripe / crypto)       │
│    → poll /api/subscription → import /sub/{token}           │
│    → connect  (kill-switch, DNS guard, auto-reconnect,      │
│                primary→fallback protocol switch)            │
└───────────────────────────────────────────────────────────┘
```

### A1.1 Trust boundaries
- The **node never holds business data** (no users, no invoices). It only knows opaque peer IDs + credentials. If a node is seized or blocked, customer/payment data is untouched.
- The **control plane never terminates VPN traffic**. It is a normal web app on 443.
- **App ⇄ control plane**: bearer token (Sanctum). **Control plane ⇄ node**: separate per-node secret, IP-allowlisted.

---

## A2. Automatic activation — the core new flow

**Design rule: activation is driven entirely by a verified payment webhook. No human in the loop.**

```
1. App: user registers (phone or email + OTP)               [FR-NEW-01]
2. App: GET /api/plans        → [{code, name, days,
                                  price_cny, price_usd, ...}]
3. App: user picks plan + method (stripe | crypto)
4. App: POST /api/checkout {plan, method, currency}
        → control plane creates Invoice(status=pending)
        → returns { pay_url, invoice_id }
5. App: opens pay_url (in-app browser / system browser)
6. User pays at Stripe / Cryptomus hosted page
7. Provider → POST /webhooks/{provider}   (signed)
        → verify signature + amount + currency
        → Invoice.status = paid
        → dispatch ProvisionSubscriptionJob
8. Job:
        → create/extend Subscription(expires_at)
        → pick node(s) per plan (region / all)
        → for each node: call node-agent "add peer"
        → generate opaque subscription token
        → Subscription.status = active
9. App: was polling GET /api/subscription
        → sees status=active, sub_url=/sub/{token}
        → imports subscription into sing-box core
        → auto-connects
```

### A2.1 New functional requirements (extend SRS §3)

| ID | Requirement |
|---|---|
| FR-NEW-01 | The system SHALL let a user self-register with phone or email + OTP, with no admin action. |
| FR-NEW-02 | The system SHALL expose plans with duration + price in **both CNY and USD**; the amount charged SHALL match the currency the user selected at checkout. |
| FR-NEW-03 | On a **signature-verified, amount-verified** payment webhook, the system SHALL activate the subscription and provision peers **automatically within 60 s**, with no admin confirmation. |
| FR-NEW-04 | The system SHALL provision the paid user as a peer on every node their plan grants, via the node-agent API, without restarting the VPN engine (hot reload). |
| FR-NEW-05 | The system SHALL serve each active subscription a rotating **opaque** subscription URL (`/sub/{token}`) that reveals no user identity and can be revoked. |
| FR-NEW-06 | A daily **expiry sweeper** SHALL disable peers whose subscription has lapsed (grace period configurable, default 0) and re-enable them on renewal. |
| FR-NEW-07 | Refund / chargeback / crypto-reversal webhooks SHALL immediately suspend the subscription and disable all its peers. |
| FR-NEW-08 | Each subscription SHALL enforce a **max simultaneous devices** limit (plan attribute), tracked by the control plane. |
| FR-NEW-09 | All webhook endpoints SHALL reject unsigned, replayed (idempotency key), or amount-mismatched events and log the attempt to the AI alert feed. |
| FR-NEW-10 | The admin SHALL be able to manually grant, extend, suspend, or refund any subscription and revoke any device/token from the Filament panel. |

### A2.2 Anti-abuse (because there is no human check)
- **Idempotency**: every webhook stored by provider event ID; duplicates ignored.
- **Amount + currency assertion**: webhook amount must equal the invoice's expected amount for its currency, within provider fee tolerance.
- **Crypto**: wait for provider "confirmed"/"finished" status (not "partially_paid"); honor `underpaid` → no activation.
- **Trial abuse**: if trials are offered, one trial per verified phone + per device fingerprint; default v1 = **no free trial**.
- **Velocity limits**: N registrations per IP / hour, N checkouts per account / hour.

---

## A3. Payments

### A3.1 Providers (v1)
| Provider | Methods it covers | Notes |
|---|---|---|
| **Stripe** | Card, **Alipay**, **WeChat Pay** | Alipay + WeChat Pay are native Stripe payment methods. Settlement currency per Stripe account. Needs a Stripe-supported business entity — **onboarding is not same-day**. |
| **Cryptomus** *(or NowPayments)* | USDT-TRC20 / USDT-BEP20 / BTC etc. | Fast onboarding; best fallback where card/Alipay fail (incl. inside China). Confirm on `paid`/`paid_over`, not `check`. |

Payment layer is a driver interface (`App\Payments\Gateway`) with `createCheckout()`, `verifyWebhook()`, `parseEvent()`. Adding Flutterwave / Selcom / Pesapal / PayPal later = one new driver, no core change.

### A3.2 Dual-currency pricing (CNY + USD)
- `plans` table stores `price_usd` and `price_cny` as **fixed authored amounts** (not FX-derived) so margins are controlled. An optional nightly FX job can *suggest* updates for the admin to approve; it never auto-changes live prices.
- `GET /api/plans` returns both. The app shows e.g. `¥28 / $3.99`. Checkout takes `currency` ∈ {`usd`,`cny`}; the invoice locks that amount.
- Stripe: create the PaymentIntent in the chosen currency (Stripe supports `cny` and `usd`). Alipay/WeChat prefer `cny`.
- Crypto: convert the chosen fiat amount to USDT at invoice-creation time via the provider's quote; lock for the invoice TTL (default 30 min).

### A3.3 Prohibited-action boundary
The build includes payment **integration** (redirect to provider-hosted checkout, receive webhooks). It does **not** and must not embed card/bank entry inside the app or handle raw card numbers — all card/Alipay/WeChat entry happens on the provider's hosted page. Crypto: the app only *displays* the provider's address/QR; MVPN never moves funds itself.

---

## A4. China / GFW connectivity requirements (extend SDD §3)

| ID | Requirement |
|---|---|
| FR-CN-01 | Primary protocol SHALL be **VLESS + REALITY** (Xray-core) on TCP 443, `dest` = a widely-trusted high-traffic TLS site not itself blocked in China, with browser-realistic uTLS fingerprint. |
| FR-CN-02 | Fallback SHALL be **Hysteria2** with **port-hopping** over a UDP port range, plus `bandwidth`/`brutal` congestion tuning. |
| FR-CN-03 | Each node's domain SHALL also be reachable via a **CDN front** (Cloudflare / Gcore) as a second entry path, so an IP block alone does not kill the node. |
| FR-CN-04 | Node VPS SHALL be on an IP range with acceptable China reachability (avoid heavily-blocked DO/Vultr/OVH ranges; prefer HK/JP/SG, ideally CN2 GIA / IPLC / "China-optimized" lines). |
| FR-CN-05 | The app SHALL auto-switch primary→fallback after `N` failed handshakes or `T` seconds (default N=3, T=8 s) and remember the last working protocol per node. |
| FR-CN-06 | The app SHALL fetch a fresh subscription (new node IPs / params) automatically when all known endpoints for a node fail (control plane can rotate a node's address). |
| FR-CN-07 | No node SHALL expose any plaintext-identifiable VPN banner on any port; non-handshake traffic on 443 SHALL receive the camouflage website. |
| FR-CN-08 | Admin SHALL be able to rotate a node's REALITY keypair / `dest` / IP and have the change reach clients via updated subscription **without users re-registering**. |

**Operational notes for the owner (not code):** keep ≥1 spare node image ready (`install.sh` one-shot), keep a spare domain, and expect to rotate IPs during "sensitive periods". Field-test from inside China for 24–72 h before advertising (SDD §9).

---

## A5. Admin control-plane (Laravel)

### A5.1 Stack
- Laravel 11, PHP 8.3, MySQL 8, Redis (queue + cache), Laravel Horizon (queue dashboard), Laravel Sanctum (app API tokens), **Filament 3** (admin UI).
- Deployed on its **own VPS** (Ubuntu 24.04) behind Caddy with automatic HTTPS. Not on any VPN node.

### A5.2 Data model (first cut)
```
users            id, phone?, email?, password?, otp_*, created_at, status
plans            code, name, days, max_devices, node_scope(json),
                 price_usd, price_cny, is_active
invoices         id, user_id, plan_code, provider, currency, amount,
                 provider_ref, status(pending|paid|failed|refunded),
                 idempotency_key, meta(json), created_at, paid_at
subscriptions    id, user_id, plan_code, status(active|expired|suspended),
                 started_at, expires_at, sub_token, max_devices
devices          id, subscription_id, fingerprint, platform, name,
                 last_seen_at, revoked_at
nodes            id, name, region, api_base, api_secret, public_host,
                 cdn_host?, status, capacity, last_health_at, health(json)
peers            id, subscription_id, node_id, protocol, remote_id,
                 secret_ref, status(active|disabled), bytes_up, bytes_down
alerts           id, severity, source, title, body, ai_summary, ack_by,
                 created_at
audit_logs       id, actor, action, target, meta, ip, created_at
```

### A5.3 Admin capabilities (Filament)
Users & subscriptions (grant / extend / suspend / refund), invoices & webhook event log, devices (revoke), nodes (register, health, capacity, rotate keys, drain), peers (view / force-disable), **AI alert feed** with acknowledge, audit log, plan & price editor (with FX suggestion), dashboards (MRR, active subs, per-node load, churn).

### A5.4 AI anomaly alerting (FR-NEW-11)
A scheduled job (every 1–5 min) collects: node health deltas, TLS cert days-remaining, sudden connection-count spikes/drops, repeated failed handshakes from single IPs (possible active probing), webhook signature failures, provisioning job failures, disk/CPU thresholds. It builds a compact JSON incident bundle and calls the **Claude API** (`claude-sonnet-5`) to classify severity (info/warn/critical), summarize, and suggest an action. Result → `alerts` table → push to **Telegram bot + email** for `critical`, dashboard-only for lower. The AI call is best-effort: raw rule-based alerts still fire if the API is unavailable.

---

## A6. Flutter client (replaces SDD §5.1 native-shell plan)

### A6.1 Foundation
Fork **Hiddify-Next** (AGPL-3.0 — comply with license: publish source of the MVPN fork, or negotiate separately). It already ships Flutter + `sing-box` via `libbox` FFI for **Android, Windows, macOS, Linux, iOS**, with TUN, per-app proxy, and subscription import. MVPN work on top:
- Rebrand (name "Mbunie VPN", icon, palette, strings) — from the Stitch screenshots.
- Replace the "add profile / paste link" onboarding with **register → plans (¥/$) → pay → auto-import**.
- Bind server list + subscription to the MVPN control plane instead of a user-pasted URL.
- Lock protocol config to MVPN's REALITY / Hysteria2 templates; keep kill-switch, DNS guard, auto-reconnect, per-node protocol fallback.
- Payment: in-app browser to Stripe / Cryptomus hosted checkout, then poll `/api/subscription`.

If an AGPL fork is unacceptable for a paid product, the alternative is a fresh Flutter shell + `sing-box` libbox bindings built in-house (larger effort, MIT-clean) — decision for the owner.

### A6.2 Platforms — all four in first release
Android (VpnService), Windows (WinTUN, app runs a helper service), macOS (NetworkExtension / system extension; ad-hoc or notarized), Linux (TUN via the app or a small root helper; AppImage + .deb). Per-platform kill-switch per SDD §5.4.

---

## A7. Revised build order

| Phase | Work | Depends on |
|---|---|---|
| **0** | Owner buys: **1 node VPS** (HK/JP/SG, China-optimized line), **1 control-plane VPS** (any reputable, EU/US ok), **1 domain** (+ ideally a 2nd spare), Cloudflare account. Start Stripe + Cryptomus onboarding **today**. | owner |
| **1** | Control plane: Laravel skeleton, data model, auth+OTP, plans, `/api/*`, Sanctum, Filament shell. Runs locally on WAMP now, no VPS needed. | — |
| **2** | Payments: Stripe driver (card/Alipay/WeChat) + Cryptomus driver, webhooks, invoices, dual-currency, **test/sandbox keys**. Provisioning job + expiry sweeper (mock node-agent). | 1 |
| **3** | Node: `install.sh` (Xray VLESS+REALITY + sing-box Hysteria2 + Caddy camouflage + ufw) + **node-agent** (Go) + control-plane ↔ agent API. Deploy to node VPS. | 0, 1 |
| **4** | Wire real provisioning: paid webhook → real peer on real node → `/sub/{token}` → manual sing-box client connects. End-to-end auto-activation proven. | 2, 3 |
| **5** | Flutter app: fork Hiddify, rebrand from screenshots, onboarding + pay + poll + auto-connect, kill-switch/DNS/fallback verified. Android build first within the same release cycle, then Windows/macOS/Linux. | 4, screenshots |
| **6** | 2nd node (reproducibility), CDN front, AI alerting job, admin dashboards, load/kill-switch/DNS-leak tests, **24–72 h field test from inside China**, go-live checklist. | 5 |

### A7.1 What "live to production today" realistically means
Same-day achievable: Phases 1–2 running locally + deployable, node `install.sh` drafted. **Not** same-day: live Stripe/Alipay merchant approval, hardened multi-platform Flutter builds, China field test. Recommended go-live: **soft launch on crypto-only payment + Android-only** as soon as Phase 4 + Android are done and one node is field-tested; add Stripe/Alipay and desktop builds as they clear.

---

## A8. Legal / compliance (owner's responsibility — restated)

Operating a **paid** VPN that markets GFW circumvention is legally sensitive: unlicensed VPN operation is illegal **inside China**; Tanzania (TCRA / EPOCA) and the hosting jurisdictions may require licensing / impose obligations; card networks and Stripe have their own acceptable-use rules for VPN resellers. Per Concept Note §9 and SDD §10, compliance, licensing, ToS, refund policy, and tax handling are the **product owner's responsibility**. The implementer builds only standard, publicly documented open-source VPN/proxy technology (Xray-core, sing-box, TLS/QUIC) and standard payment integrations.

---

## A9. Guardrails still in force (from 04 §3, unchanged)
1. No content/destination logging anywhere server-side.
2. No plaintext credential storage on any client (platform secure storage only).
3. Key-only SSH on every node and the control plane.
4. Kill-switch fails **closed**.
5. Only standard, publicly documented open-source components.
Plus, new: **6. No raw card/bank data ever touches MVPN code — provider-hosted checkout only.**

---
*End of Addendum 1*

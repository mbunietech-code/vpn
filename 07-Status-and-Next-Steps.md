# MVPN — Status & Next Steps

**Updated:** 2026-09-03 · **Repo:** `github.com/mbunietech-code/vpn`

---

## Live now

| Piece | State |
|---|---|
| **Control plane** | 🟢 LIVE `https://vpn.mbuniehub.com` (Hostinger shared hosting, behind Cloudflare) |
| Admin panel | 🟢 `/admin` — Filament, "Settings & Keys", "Payment Methods", Invoices w/ approve/reject |
| Email OTP | 🟢 working (Hostinger SMTP; Brevo API supported as fallback) |
| Auth / plans / subscription API | 🟢 |
| **Payments (v1) — manual QR + admin approve** | 🟢 built & tested — *owner must add real QR codes in admin* |
| Payments (v2) — Stripe/Cryptomus auto | ⚪ built, behind `/api/checkout`, needs merchant KYC |
| **Flutter app** | 🟢 premium redesign, all screens; `dart analyze` clean, tests green |
| Desktop VPN engine (Win/Linux/mac) | 🟡 built (sing-box subprocess) — **untested, needs a node** |
| Android real tunnel | 🟡 Hiddify **light fork** in `android-app/` (`com.mbunie.mvpn.engine`) — rebrand + `mvpn://` deep-link consumer in progress; account app hands off the subscription, engine imports + connects one-tap |
| ClickPesa (TZS: M-Pesa/Tigo/Airtel/bank) | 🟡 gateway on real `/webshop/generate-checkout-url` endpoint, verified live — owner must enter creds in admin + set webhook + TZS plan prices |
| **Node (Vultr Tokyo)** | 🔴 not deployed — owner funding Vultr |

### Android two-app model
The account app (`com.mbunie.mvpn`) does auth, plans, payment, subscription. On Android it does **not** tunnel — the **Mbunie VPN Engine** (`com.mbunie.mvpn.engine`, the Hiddify light fork) does. Home's Connect button launches `mvpn://import?url=<subUrl>&name=Mbunie VPN` into the engine (then bare `mvpn://connect` on later taps); if the engine isn't installed the app opens `https://vpn.mbuniehub.com/download/android`.
Owner: stage the engine APK at `storage/app/public/downloads/mbunie-vpn-engine.apk` **or** set the `android_download_url` key in *Settings & Keys*.

---

## Immediate next steps

### Owner
1. **Deploy latest:** `cd ~/domains/mbuniehub.com/mvpn && git pull && bash control-plane/deploy.sh`
2. **Admin → Payment Methods:** add Alipay + WeChat QR images + instructions.
3. **Admin → Plans:** set real prices (¥ and TZS).
4. **Admin → Settings & Keys:** enter ClickPesa `client-id` / `api-key` / `merchant ID`; set ClickPesa webhook URL to `https://vpn.mbuniehub.com/webhooks/clickpesa`.
5. **Fund Vultr** → deploy Tokyo node (Shared CPU High Performance 2 GB, Ubuntu 24.04).
6. DNS in Cloudflare: `n1.mbuniehub.com` A → Vultr IP, **DNS-only (grey)**.

### Engineering (after node exists)
6. SSH node → `bash node/install.sh …` → register node in admin.
7. `bash control-plane/scripts/fetch-singbox.sh 1.11.15` on the control plane.
8. End-to-end test: register → manual pay → admin approve → connect (desktop engine + stock Hiddify).
9. Verify kill-switch + DNS-leak on desktop.
10. Field test 24–72 h from inside China.

### Then
11. Android: fork Hiddify-Next, rebrand, wire the MVPN `AppState` flow.
12. Windows installer + icon; macOS notarize; Linux .deb/AppImage.
13. Localization (SW/EN/ZH), device-limit screen, auto-update.
14. Legal review (VPN sale; China; TCRA Tanzania).
15. Second node + CDN front + `/api/checkout` (Stripe/Cryptomus) for v2.

---

## Guardrails still in force
No content logging · no plaintext client creds · key-only SSH on nodes · kill-switch fails closed ·
standard OSS only · no raw card/bank data in MVPN code.

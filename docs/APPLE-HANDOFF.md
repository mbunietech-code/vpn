# Mbunie VPN on macOS and iPhone — handoff

Written 2026-10-12 on the Windows machine, for the session that continues on a Mac.
Read this first. Everything here is the state of the `main` branch at that date.

## What exists today

| Part | Where | State |
|---|---|---|
| Control plane (Laravel 13 + Filament 4) | `control-plane/` | Live at https://vpn.mbuniehub.com |
| Flutter app | `client/` | Android + Windows shipped (v1.0.13); `macos/` folder exists, never built; **no `ios/` folder** |
| VPN node KL1 | Hostinger KL VPS, shared with EduHub LiveKit | VLESS-REALITY + Hysteria2 via Xray / sing-box |
| EduHub integration | separate repo `edu/prod` | Sells VPN plans, manages VPN via signed partner API |

How the app gets its VPN config: after sign-in (email/phone one-time code) the app calls
`GET /api/subscription` → `sub_url`, then downloads
`{sub_url}?format=singbox&platform=<android|windows|macos>&protocol=auto` — a complete
sing-box JSON config. Server side: `control-plane/app/Services/SubscriptionBuilder.php`.
The app also sends `X-Mvpn-Device` (stable device id) on every call; see
`client/lib/services/app_state.dart` and `client/lib/services/api_client.dart`.

Engines in the app (`client/lib/services/tunnel/`):
- `android_hiddify_engine.dart` — Android, libbox embedded via Kotlin (`client/android/.../MethodHandler.kt`, `VPNService.kt`).
- `singbox_engine.dart` — desktop: downloads/uses a bundled `sing-box` binary and runs it with a TUN inbound.
  Already knows `Platform.isMacOS` (`darwin-arm64` / `darwin-amd64`); the server can serve
  `/bin/sing-box/darwin-arm64` if the binaries are put in `control-plane/storage/app/bin/`.

## Rules that must not be broken

- English only in the app, the admin and emails.
- Never print or commit secrets (Stripe keys, partner secret, node API secret, OpenVPN configs).
- Deploy the control plane only with:
  `ssh -p 65002 u125644160@46.202.182.173 'cd ~/domains/mbuniehub.com/mvpn && git pull --ff-only origin main && bash control-plane/deploy.sh'`
  (never hPanel Git). The node KL1 also runs EduHub's LiveKit: never touch port 443 or reset ufw there.
- Bump `client/lib/config.dart` `appVersion`, `client/pubspec.yaml` and `control-plane/config/services.php`
  (`app_version` + download links) together for every release; the app's update notice reads `/api/app/version`.

## macOS — the quicker one

Option A (fast, outside the App Store, same as Windows):
1. `cd client && flutter build macos`.
2. Bundle `sing-box` (darwin-arm64 + darwin-amd64, same version as Windows: 1.14.x) inside the app
   and make `singbox_engine.dart` look there first (it already prefers `<app>/engine/` on Windows).
3. A TUN interface needs root on macOS: run sing-box through a privileged helper
   (`SMAppService` daemon) or ask for the admin password with `osascript ... with administrator privileges`.
4. Sign with a **Developer ID** certificate and **notarize**, ship a `.dmg`, add it to the download page
   (`control-plane/resources/views/download.blade.php` already has a macOS tile marked "Coming soon").

Option B (App Store / cleanest): a Network Extension like iOS below, shared with the iPhone app.

## iPhone — needs a Network Extension

iOS does not allow a normal app to create a VPN. It needs:
- a **Packet Tunnel Provider** app extension (NetworkExtension framework),
- the **Network Extensions** capability (Packet Tunnel) and an **App Group** shared by app + extension,
- **libbox** for Apple: build `Libbox.xcframework` from sing-box with gomobile
  (`make lib_apple` / `cmd/internal/build_libbox` in the sing-box repo, same version as the server and Android),
- a small Flutter ↔ native bridge (MethodChannel `com.hiddify.app/method` is what Android uses; mirror
  `setup` / `start` / `stop` / `device_info` so `android_hiddify_engine.dart` can become a mobile engine).

Steps: `flutter create --platforms=ios .` inside `client/`, set the bundle id (e.g. `com.mbunie.mvpn`),
add the extension target in Xcode, embed Libbox, write `PacketTunnelProvider.swift`
(start libbox with the config JSON fetched by the app and passed through the App Group),
then the Dart side. Reference implementation: the official sing-box Apple client (SFI) is open source.

## Apple accounts and China

- An **Apple Developer Program** membership ($99/year) is required for Network Extensions and TestFlight.
  Enrolling as an organisation (Mbunie Tech) needs a D-U-N-S number; individual enrolment is faster.
- **VPN apps are not available in the China App Store.** iPhone users in China install through:
  a non-China Apple ID (App Store), **TestFlight** (up to 10,000 testers), or Ad Hoc (100 devices).
- Stop-gap that works today: iPhone users can install the official **sing-box** app (non-China App Store)
  and import their personal subscription link
  `https://vpn.mbuniehub.com/sub/<token>?format=singbox&platform=ios&protocol=auto`
  (admin → Subscriptions → ⋮ → Subscription link shows it with a QR code).
  **Not ready yet:** `SubscriptionLinkController` only accepts `windows|linux|macos|android` and
  falls back to the Windows config for anything else. Add `ios` there and in
  `SubscriptionBuilder::buildSingbox()` (treat it like `android`: mobile TUN, `gvisor` stack, no
  desktop-only route options), then test with the sing-box app before telling customers.

## Mac setup checklist

1. Xcode (App Store) + `xcode-select --install`, CocoaPods (`sudo gem install cocoapods` or Homebrew).
2. Flutter SDK (stable), `flutter doctor` all green for iOS/macOS.
3. Go 1.22+ and `gomobile` (only for building Libbox).
4. `git clone https://github.com/mbunietech-code/vpn.git`
5. Sign in to Xcode with the Apple ID of the developer account; plug in the iPhone, enable Developer Mode.
6. Claude Code on the Mac: open the cloned folder and point it at this file.

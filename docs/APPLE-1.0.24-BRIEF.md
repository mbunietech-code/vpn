# Brief for Claude on the Mac: Mbunie VPN 1.0.24 for macOS and iPhone

Written 2026-11-11 on the Windows PC. You are Claude Code running on the owner's Mac.
Your job is to ship **Mbunie VPN 1.0.24** for **macOS** and **iPhone**, matching the
Windows and Android 1.0.24 that are already live on https://vpn.mbuniehub.com/download.

Read `docs/APPLE-HANDOFF.md` first. It has the background: the Mac setup checklist,
how the macOS helper works, the iOS Network Extension plan, and Apple account and China notes.
This brief adds what changed since then and what "done" means now.

The owner speaks Swahili. Keep your reports to them short and in simple Swahili.
Keep code, comments and commit messages in English.

## 0. Get the code

```bash
git clone https://github.com/mbunietech-code/vpn.git mbunie-vpn   # or: git fetch origin
cd mbunie-vpn
git checkout claude/app-1.0.22          # the branch that holds Windows/Android 1.0.24
git checkout -b claude/apple-1.0.24     # do your work here
```

Check that `client/pubspec.yaml` says `version: 1.0.24+25` and that
`client/lib/config.dart` has `appVersion = '1.0.24'`. If they don't, stop and tell the
owner the 1.0.24 code was not pushed yet.

**Keep the version as it is.** macOS and iPhone ship as 1.0.24 (build 25), the same as
Windows and Android. Do not bump it.

## 1. What 1.0.24 contains (Apple builds must behave the same)

All of this is in shared Dart code. macOS gets it automatically. iPhone must be wired to it.

- `client/lib/services/tunnel/rule_sets.dart` → `localizeRuleSets(config, dir, http)`.
  - Before the engine starts, the app downloads the China rule sets (`geosite-cn`, `geoip-cn`) itself, saves them to `<dir>/rules/<tag>.srs`, and rewrites them in the config as `type: local`.
  - Why: the URLs can point at the KL relay (`https://187.52.115.154:2096`), whose certificate is self-signed. The app trusts it (pinned in `relay.dart`), but sing-box does not. The old result was FATAL `x509: certificate signed by unknown authority`, so the VPN never connected.
  - If there is no internet and no cached copy, the rule sets are dropped instead of crashing. Traffic then still flows, through the tunnel.
- `client/lib/services/friendly_error.dart` → customers only see short messages (`vpn.err.*` in `l10n/app_text.dart`: en, sw, zh). The full error goes to the server in the heartbeat and shows under Admin › Devices › Last error.
- `vpn_controller.dart` checks for "connected but no internet" with `GET http://127.0.0.1:9095/proxies/proxy/delay?...`. This is sing-box's Clash API, which the server config already turns on. After 2 failures in a row the app warns `vpn.err.noTraffic`.
- `api_client.dart` leaves the relay fallback after 10 minutes, so the app goes back to the main address.
- Tests: `client/test/connect_errors_test.dart`. Run `cd client && flutter test` and keep it green.

**China rules must not change.** Do not edit the China parts of
`control-plane/app/Services/SubscriptionBuilder.php`:
- `route.rules`: `rule_set [geosite-cn, geoip-cn] → direct`
- `dns.rules`: `geosite-cn → direct-dns`

The only change the app makes is to turn the remote rule sets into local files.

## 2. macOS 1.0.24 (already supported, so build, test and package)

The macOS app already runs sing-box through the root helper (`client/macos/engine-scripts/`).

```bash
cd client
flutter pub get
flutter test
bash macos/engine-scripts/fetch-engine.sh 1.14.0   # same sing-box as Windows
sh macos/engine-scripts/make-dmg.sh                # → build/macos/Mbunie-VPN-1.0.24-macos.dmg
```

Test on this Mac before calling it done:
1. Open the DMG build, sign in, and press Connect. Enter the Mac password once.
2. Check that a website loads and that https://ipinfo.io shows the VPN server's IP.
3. Open the config the app wrote (Application Support → `config.json`). Check:
   - `route.rule_set` entries are `"type": "local"`, and their `.srs` files exist;
   - the China rules listed above are still there.
4. Turn Wi-Fi off while connected. Within about a minute the app should show the short "no internet" warning, not a long error.
5. Disconnect, then quit the app. No `sing-box` process should be left running (`pgrep sing-box`).

Signing: it is still ad-hoc unless the owner has a Developer ID. If they do, sign and notarize as `APPLE-HANDOFF.md` describes.

## 3. iPhone 1.0.24 (new work: Network Extension)

Follow the "iPhone" section of `APPLE-HANDOFF.md`. Concretely:

1. Run `cd client && flutter create --platforms=ios --org com.mbunie .`
   - Bundle id `com.mbunie.mvpn`, display name "Mbunie VPN".
   - Version comes from pubspec: 1.0.24 (25).
2. Add a **Packet Tunnel Provider** extension target `com.mbunie.mvpn.tunnel`.
   - Give it the **Network Extensions (packet-tunnel-provider)** capability.
   - Give both targets the App Group `group.com.mbunie.mvpn`.
3. Build `Libbox.xcframework` from **sing-box v1.14.0** (same as Windows, macOS and the server) with `gomobile` (`go run ./cmd/internal/build_libbox -target apple`). Embed it in the extension.
   - The official sing-box Apple client (SFI) is the reference for `PacketTunnelProvider.swift`.
4. Write a MethodChannel bridge (for example `com.mbunie.mvpn/vpn`) with:
   - `start(configPath)`, `stop()` and a status stream (`connecting / up / down / error + message`);
   - start and stop through `NETunnelProviderManager`, saving the profile on first use. That first save shows iOS's "Add VPN configuration" prompt.
5. Dart: add `client/lib/services/tunnel/ios_engine.dart`, implementing `TunnelEngine`, and select it in the place that picks engines by platform. Model it on `android_hiddify_engine.dart`:
   - fetch `{sub_url}?format=singbox&platform=ios&protocol=<p>` through `Relay.get` (the server already supports `platform=ios`: gvisor stack);
   - keep a `last-<protocol>.json` fallback, as Android does;
   - call `localizeRuleSets(config, <App Group container dir>, _http)`. The `.srs` files **must be inside the App Group container**, or the extension cannot read them;
   - write the config into the App Group container and pass its path to `start`;
   - pass engine error text through unchanged; `friendly_error.dart` decides what the customer sees.
6. Check that the no-internet probe (`127.0.0.1:9095` from the app while the extension runs sing-box) works on a real iPhone. If it does not, make the probe skip quietly on iOS. Do not show a false warning.
7. Updates: iOS cannot install an app from a link. In `app_state.dart` `checkForUpdate`, skip iOS, or point it at the TestFlight/App Store link once there is one.
8. Keep the network extension's memory low: its limit is about 50 MB. Test with the China rule sets loaded.
9. Test on a real iPhone. Run the same checks as the macOS list above, using the iPhone's Wi-Fi and mobile data.

Distribution needs the owner's **Apple Developer Program** account. See the Apple and China notes in `APPLE-HANDOFF.md`: there is no China App Store, so use TestFlight or a non-China Apple ID.
- If there is no account yet, still finish and test the build on a device with a free/personal team. Then tell the owner exactly what is missing.

## 4. Rules

- **Do not change Windows or Android behaviour.** If you change shared Dart code, keep it platform-guarded and run `flutter test`. Also run `flutter analyze` and fix only what you introduced.
- Never print or commit secrets.
- Do not deploy to the server or change its `.env` without the owner's OK. When they agree:
  - Server: `ssh -p 65002 u125644160@46.202.182.173`, app at `~/domains/mbuniehub.com/mvpn/control-plane`.
  - Use **`/opt/alt/php84/usr/bin/php`**, not plain `php` (that is 8.3 and breaks artisan).
  - The live server currently gets versions from `.env` overrides, not from `config/services.php`. Back up `.env` first (`cp .env ~/env-backups/mvpn.env.$(date +%s)`).
  - Upload the DMG to `storage/app/public/downloads/Mbunie-VPN-1.0.24-macos.dmg` and check that its sha256 matches.
  - Set `MVPN_DOWNLOAD_MACOS=/storage/downloads/Mbunie-VPN-1.0.24-macos.dmg` and `MVPN_APP_VERSION=1.0.24`. `/api/app/version` has no `macos` key, so macOS devices read the base `app_version`.
  - Then run `/opt/alt/php84/usr/bin/php artisan config:cache`.
  - Verify: https://vpn.mbuniehub.com/download shows macOS 1.0.24 and the file returns 200.
  - Optional server improvement: add `macos_version` / `ios_version` next to `android_version` / `windows_version` in `config/services.php` and `DownloadController::appVersion`. Do this in a separate commit and ask first.
- KL1 node: never touch port 443 or ufw there (it also runs LiveKit).
- Commit on `claude/apple-1.0.24`, push, and open a PR into `main`. Do not merge without the owner's OK.

## 5. Done means

- [ ] `flutter test` passes on the Mac.
- [ ] macOS 1.0.24 DMG is built and tested (connect, real IP changes, China rules intact, no-internet warning, clean quit).
- [ ] iPhone 1.0.24 is built and tested on a real iPhone (same checks).
- [ ] Code is on `claude/apple-1.0.24`, pushed, with a PR.
- [ ] macOS DMG is live on /download (after the owner said yes).
- [ ] Short Swahili report to the owner: what works, what was tested, and what still needs their Apple account.

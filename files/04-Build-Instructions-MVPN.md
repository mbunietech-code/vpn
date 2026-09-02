# FULL BUILD INSTRUCTIONS
## Mbunie VPN (MVPN) — Handoff Brief for Implementing Developer / AI Agent

**Document Version:** 1.0
**Read this together with:** `01-Concept-Note-MVPN.md`, `02-SRS-MVPN.md`, `03-SDD-MVPN.md`
**Audience:** The developer or AI coding agent (e.g., Claude Code) responsible for actually building MVPN.

---

## 0. How to Use This Document

You (the implementer) have been given four documents:
1. Concept Note — why this exists and what it's for.
2. SRS — what the system must do (requirements, `FR-*`/`NFR-*` IDs).
3. SDD — how the system is architected (modules, protocols, data, deployment).
4. This document — the concrete, ordered build plan.

Do not deviate from the protocol choices, security requirements, or no-logging policy defined in the SRS/SDD without flagging the change explicitly to the project owner. Everything else (exact code structure, library versions, minor UI layout) is at your engineering discretion, guided by the SDD.

**Scope reminder:** This is a personal/private VPN system for lawful use by its owner. Build it using standard, publicly documented, open-source VPN/proxy technology only (Xray-core, sing-box, standard TLS/QUIC). Do not implement anything targeting third-party systems, exploits, or infrastructure you do not own or have explicit permission to operate on.

---

## 1. Recommended Build Order (Phased)

### Phase 0 — Prerequisites (Project Owner Provides)
- [ ] A cloud VPS (Ubuntu 22.04/24.04), root/sudo SSH access.
- [ ] A registered domain name, with DNS access to create A/AAAA records.
- [ ] Decision on hosting region(s) for node 1 (and optionally node 2 for redundancy).

*If these are not yet provided, pause and request them before proceeding past Phase 1.*

### Phase 1 — Server Core (MVPN-Server v1.0)
**Goal:** A working, DPI-resistant VPN endpoint reachable by a manually-configured test client.

1. Set up the base VPS: update packages, create a non-root sudo user, configure SSH key-based login, disable password auth.
2. Install Xray-core.
3. Configure a **VLESS + REALITY** inbound on port 443 as the primary protocol (per SDD §3.1). Choose a realistic, popular HTTPS site as the REALITY `dest`/target (a widely-trusted, high-traffic site whose TLS fingerprint is common).
4. Install and configure Caddy (or Nginx) to serve a simple, real-looking static website as camouflage, coordinated with Xray so that non-VPN HTTPS requests to the domain get the normal website (per SDD §3.4).
5. Install sing-box and configure a **Hysteria2** inbound as the fallback protocol (per SDD §3.3), on a separate port (e.g., UDP 8443) or via port-multiplexing if supported.
6. Optionally configure a **Trojan-over-TLS** inbound as a secondary protocol (requires a valid Let's Encrypt cert via Caddy/certbot) — per SDD §3.2.
7. Configure firewall (ufw/nftables): allow only SSH (ideally non-default port, key-only) and the VPN/HTTPS port(s); deny everything else.
8. Wrap all of the above into an idempotent `install.sh` bootstrap script (per SDD §4.4.1) so the whole node can be rebuilt from scratch on a fresh VPS in one run.
9. Configure systemd units for Xray and sing-box with auto-restart on failure.
10. **Manual validation:** From an unrestricted network, use a general-purpose client (e.g., a reference `sing-box`/`v2rayN`/`NekoBox`-compatible config) to connect and confirm internet access flows correctly through the tunnel, and confirm visiting the domain in a normal browser (no VPN client) shows the camouflage website.

**Deliverable:** Working single-node server + `install.sh` + a manually-verified test connection.

### Phase 2 — Peer/Admin Tooling (MVPN-Admin v1.0)
**Goal:** Scripts to add/revoke/list peers and generate shareable configs.

1. Implement `peers.json` per SDD §7.1.
2. Implement `add-peer.sh <label>` (SDD §4.4.2): generates credentials, updates config, reloads service, outputs a **subscription link** and a **QR code** (PNG saved locally + terminal ASCII QR).
3. Implement `revoke-peer.sh <peer_id>` (SDD §4.4.3).
4. Implement `list-peers.sh` and `node-status.sh` (SDD §4.4.4).
5. **Validation:** Add a peer, generate its config/QR, and confirm you can import it into a general-purpose reference client and connect successfully.

**Deliverable:** Full peer lifecycle manageable from the CLI without editing raw config files by hand.

### Phase 3 — Client v1.0 (Recommended Fast Path per SDD §5.1)
**Goal:** A working MVPN-branded client on at least 2 platforms (recommend: **Android first**, then **Windows or Desktop-Linux second**, since these are typically fastest to get a working VPN-service integration on).

1. Choose the client foundation: adapt/build on top of `sing-box`'s official client libraries/mobile bindings rather than writing protocol handling from scratch (per SDD §5.1 "recommended path"). Confirm this library choice supports VLESS+REALITY, Trojan, and Hysteria2 (it does, as of current `sing-box` releases — verify version compatibility at build time).
2. **Android build:**
   - Implement `VpnService`-based tunnel integration.
   - Implement `ConfigManager` (import via QR scan / paste link / manual JSON).
   - Implement `ConnectionController` with primary→fallback logic (FR-CLI-09).
   - Implement Status screen (Connected/Disconnected/Connecting), Node list screen, Settings screen (kill-switch toggle, auto-connect, auto-reconnect — FR-CLI-12).
   - Implement kill-switch (SDD §5.4 Android notes) and DNS leak guard (SDD §5.5).
   - Apply MVPN branding (app name "Mbunie VPN", icon, color scheme — to be supplied by project owner or use a clean placeholder if not yet provided).
3. **Second platform build** (Windows or Linux, per project owner preference): mirror the same feature set using the platform-specific integration approach from SDD §5.1/§5.4.
4. **Validation:** Full connect → browse → disconnect cycle on each built platform, plus a kill-switch drop test (kill the VPN process mid-session and confirm traffic is blocked, not passed through in the clear).

**Deliverable:** Two working client apps meeting FR-CLI-01 through FR-CLI-13 (core set) that successfully connect to the Phase 1/2 server.

### Phase 4 — Remaining Platforms
Repeat the client build pattern (Phase 3, step 3) for the remaining target platforms (iOS, macOS, and/or the platform not covered in Phase 3), respecting each platform's native VPN API as specified in SDD §5.4.

### Phase 5 — Multi-Node & Hardening
1. Stand up a second server node in a different region using `install.sh` (validates reproducibility, NFR-MAI-01).
2. Add the second node to client node-selection lists.
3. Run the field test described in SDD §9 (sustained 24–72 hour connection test from the actual restrictive-network environment the owner will use it in).
4. Review against the full NFR checklist in SRS §4 and the security checklist below before considering v1.0 "done."

---

## 2. Definition of Done — v1.0 Checklist

Copy this checklist into your task tracker and confirm each item before declaring the build complete:

**Server**
- [ ] `install.sh` rebuilds a fully working node on a fresh VPS unattended.
- [ ] VLESS+REALITY primary protocol working on port 443.
- [ ] At least one fallback protocol (Hysteria2 and/or Trojan) working.
- [ ] Camouflage website serves correctly to non-VPN visitors.
- [ ] No destination/content logging is enabled anywhere in the stack (FR-SRV-05, NFR-SEC-03).
- [ ] SSH is key-only, password auth disabled.
- [ ] Firewall allows only necessary ports.

**Admin**
- [ ] `add-peer.sh`, `revoke-peer.sh`, `list-peers.sh`, `node-status.sh` all functional.
- [ ] Subscription link + QR generation works end-to-end.

**Client (per built platform)**
- [ ] Import config via link/QR/manual.
- [ ] Connect/Disconnect works reliably.
- [ ] Status display accurate in real time.
- [ ] Kill-switch verified (process-kill test shows traffic blocked, not leaked).
- [ ] DNS leak test shows no leaks while connected (verify with a DNS leak test tool/method).
- [ ] Auto-reconnect verified (toggle Wi-Fi off/on mid-session).
- [ ] Protocol fallback verified (block primary port locally/via firewall rule and confirm fallback engages).
- [ ] Credentials stored only in platform secure storage, never plaintext on disk.

**Field Validation**
- [ ] Sustained connection test completed from the actual target restrictive network for at least 24 hours without manual intervention.

---

## 3. Non-Negotiable Guardrails (Do Not Skip)

1. **No content/destination logging** anywhere server-side (SRS NFR-SEC-03).
2. **No plaintext credential storage** on any client (SRS NFR-SEC-02, FR-CLI-11).
3. **Key-only SSH**, no password auth left enabled on any node (SRS NFR-SEC-04).
4. **Kill-switch must fail closed**, not fail open — if in doubt, block traffic rather than leak it (FR-CLI-05).
5. Use only standard, publicly-documented open-source components (Xray-core, sing-box, Caddy/Nginx, standard TLS/QUIC libraries). Do not write custom exploit code, do not target infrastructure you don't own.

---

## 4. Open Decisions for the Project Owner (Ask If Not Already Answered)

If any of the following have not been specified by the time you reach the relevant phase, pause and ask the project owner rather than guessing:

- Which cloud VPS provider and region(s)? (Phase 0)
- Which domain name will be used? (Phase 0)
- Second client platform priority after Android — Windows or Linux? (Phase 3)
- Branding assets (app icon, color palette, exact app name string) — or proceed with a clean placeholder? (Phase 3)
- Is an Apple Developer account available (needed for iOS/macOS TestFlight or notarized builds), or is sideloading/personal-team-only acceptable? (Phase 4)

---

## 5. Suggested First Message to Give the Implementing Agent

> "Here are four documents: Concept Note, SRS, SDD, and Build Instructions for a project called Mbunie VPN (MVPN). Please read all four fully, then begin at Phase 0/Phase 1 of the Build Instructions. Confirm the Phase 0 prerequisites with me before writing any server code. Follow the SRS requirements and SDD architecture exactly; ask me only for the 'Open Decisions' listed in Build Instructions §4, and otherwise use your engineering judgment for implementation details."

---
*End of Build Instructions*

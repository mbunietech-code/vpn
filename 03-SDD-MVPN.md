# SYSTEM / SOFTWARE DESIGN DOCUMENT (SDD)
## Mbunie VPN (MVPN)

**Document Version:** 1.0
**Companion Document:** SRS-MVPN v1.0 (all FR/NFR IDs referenced below trace back to that document)
**Status:** Draft for Development Handoff

---

## 1. Introduction

### 1.1 Purpose
This SDD translates the requirements in SRS-MVPN into a concrete technical architecture, module breakdown, data design, protocol design, and deployment design, sufficient for direct implementation.

### 1.2 Design Goals (mapped to SRS)
- DPI-resistant transport (FR-SRV-01/02, NFR-SEC-05)
- Cross-platform client with shared core (NFR-POR-01)
- Fail-safe networking: kill-switch + DNS leak protection (FR-CLI-05/06)
- Reproducible infrastructure (NFR-MAI-01)
- No content logging (NFR-SEC-03)

---

## 2. System Architecture Overview

```
                        ┌───────────────────────────────┐
                        │        MVPN NODE (VPS)         │
                        │                                │
   Internet  ─────────► │  Nginx/Caddy (port 443)        │
                        │   ├─ if VPN handshake → Xray/   │
                        │   │   sing-box inbound          │
                        │   └─ else → serve fake website  │
                        │                                │
                        │  Xray-core / sing-box engine    │
                        │   ├─ VLESS + REALITY (primary)  │
                        │   ├─ Trojan-TLS (secondary)      │
                        │   └─ Hysteria2/QUIC (fallback)  │
                        │                                │
                        │  Peer store (config JSON /      │
                        │  simple DB)                     │
                        │                                │
                        │  systemd services + firewall    │
                        │  (ufw/nftables)                 │
                        └───────────────┬────────────────┘
                                        │ (control plane, SSH/API)
                                        │
                        ┌───────────────▼────────────────┐
                        │      MVPN-ADMIN (CLI/scripts)   │
                        │  add-peer / revoke-peer /       │
                        │  list-peers / node-status        │
                        └──────────────────────────────────┘

        ┌────────────────────────────────────────────────────┐
        │                  MVPN-CLIENT (per platform)          │
        │  ┌───────────────┐   ┌─────────────────────────┐    │
        │  │  UI Shell      │◄─►│  Core Engine (shared)    │    │
        │  │ (native/Flutter│   │  - config parser          │    │
        │  │  per platform) │   │  - Xray/sing-box binding   │    │
        │  └───────────────┘   │  - kill-switch controller  │    │
        │                       │  - DNS leak guard           │    │
        │                       │  - reconnect/fallback logic │    │
        │                       └─────────────────────────┘    │
        └────────────────────────────────────────────────────┘
```

### 2.1 Subsystems

1. **MVPN-Server** — the VPS-hosted VPN endpoint(s) (one or more nodes).
2. **MVPN-Client** — cross-platform application (Android, iOS, Windows, macOS, Linux).
3. **MVPN-Admin** — CLI/scripts (and optionally a minimal REST API) for peer and node management.

---

## 3. Protocol & Security Design

### 3.1 Primary Protocol: VLESS + REALITY (via Xray-core)
- **Why:** REALITY allows the server to present the *genuine* TLS certificate of a real, popular website (the "target"/"dest") during the handshake for any prober or DPI system that isn't holding the correct private key, while legitimate MVPN clients (holding the correct key) are transparently proxied to the real Xray service. This defeats active-probing detection, one of the most effective censorship techniques against traditional TLS-in-TLS VPNs.
- **Port:** 443 (blends with normal HTTPS traffic).
- **Fingerprint:** Client TLS fingerprint SHOULD mimic a common browser (e.g., Chrome) via `uTLS`/fingerprint randomization supported by Xray-core.

### 3.2 Secondary Protocol: Trojan-over-TLS
- **Why:** Simpler fallback; behaves like an HTTPS reverse proxy; widely supported by clients.
- Requires a real domain + valid Let's Encrypt certificate (unlike REALITY, which can use a borrowed identity).

### 3.3 Fallback Protocol: Hysteria2 (QUIC/UDP-based)
- **Why:** Uses UDP/QUIC, which some networks handle differently than TCP-based DPI chains; also offers strong throughput via BBR-like congestion control. Useful as an automatic fallback if TCP-based protocols (VLESS/Trojan) are being actively disrupted.

### 3.4 Traffic Camouflage
- Nginx/Caddy on the same server listens on port 443.
- SNI-based or path-based routing: genuine-looking requests (no valid VLESS/Trojan handshake) are transparently served a real static website (e.g., a simple business/blog site) so that manual inspection or automated scanning of the IP/port sees "just a website."

### 3.5 Encryption Summary

| Layer | Mechanism |
|---|---|
| Transport | TLS 1.3 (REALITY) or standard TLS 1.3 (Trojan) or QUIC/TLS 1.3 (Hysteria2) |
| Application payload | AEAD ciphers per protocol default (e.g., AES-128-GCM/ChaCha20-Poly1305) |
| Client secret storage | Platform secure storage (Keystore/Keychain/DPAPI/Secret Service) |
| Admin/API access | SSH key-based; optional REST API secured with bearer tokens over HTTPS only |

### 3.6 No-Logging Policy Implementation
- Access logs at the reverse proxy and VPN engine level SHALL be configured to omit destination host/path details for tunneled connections; only aggregate connect/disconnect timestamps and byte counters are retained for operational monitoring (satisfies FR-SRV-05, NFR-SEC-03).

---

## 4. Server-Side Design (MVPN-Server)

### 4.1 Recommended Minimum Node Specs
- 1 vCPU, 1–2 GB RAM, 20 GB SSD (sufficient for personal/small-group use)
- Ubuntu 22.04/24.04 LTS
- Located in a jurisdiction with good international connectivity and a reputable, non-blocked IP range

### 4.2 Software Stack
| Component | Choice |
|---|---|
| VPN/proxy engine | Xray-core (primary), sing-box (for Hysteria2/multi-protocol flexibility) |
| Reverse proxy / camouflage web server | Caddy (automatic HTTPS) or Nginx (manual cert management) |
| Process supervision | systemd |
| Firewall | ufw (simple) or nftables (advanced) |
| Certificate management | Caddy's built-in ACME, or certbot for Nginx |
| Peer/config storage | JSON file store initially (`/etc/mvpn/peers.json`); can migrate to SQLite later |
| Server automation | Bash scripts + optional Ansible playbook |

### 4.3 Directory Layout (Server)

```
/etc/mvpn/
  ├─ config/
  │   ├─ xray-config.json         # main Xray inbound/outbound config (templated)
  │   ├─ singbox-config.json      # Hysteria2 fallback config
  │   └─ peers.json               # peer registry (id, protocol, key, created_at, status)
  ├─ scripts/
  │   ├─ install.sh                # full node bootstrap
  │   ├─ add-peer.sh
  │   ├─ revoke-peer.sh
  │   ├─ list-peers.sh
  │   ├─ gen-subscription.sh       # builds subscription link / QR for a peer
  │   └─ node-status.sh
  ├─ tls/
  │   └─ (certs if not using REALITY)
  └─ www/
      └─ (fake camouflage website content)
```

### 4.4 Key Server Workflows

**4.4.1 Node Bootstrap (`install.sh`)**
1. Update OS packages; harden SSH (disable password auth, change default port optionally).
2. Install Xray-core and sing-box binaries.
3. Install and configure Caddy/Nginx; deploy camouflage website content to `/etc/mvpn/www/`.
4. Generate REALITY key pair (private/public) and Trojan credentials.
5. Template `xray-config.json` and `singbox-config.json` with generated keys and chosen `dest`/SNI target.
6. Configure firewall (ufw): allow 22 (SSH, ideally key-only + non-default port), 443 (VPN/HTTPS); deny all else inbound.
7. Enable and start systemd services for Xray, sing-box, and the reverse proxy.
8. Run a self-test (local curl to camouflage site + local test client handshake) and report status.

**4.4.2 Add Peer (`add-peer.sh <label>`)**
1. Generate a new UUID (VLESS) / password (Trojan) for the requested label.
2. Append entry to `peers.json` with `id`, `label`, `protocol`, `created_at`, `status: active`.
3. Re-render `xray-config.json` inbound client list; reload Xray (`systemctl reload xray` or config-reload API).
4. Call `gen-subscription.sh` to output a shareable link and QR code (terminal QR + saved PNG) for the new peer.

**4.4.3 Revoke Peer (`revoke-peer.sh <peer_id>`)**
1. Mark peer `status: revoked` in `peers.json`.
2. Re-render config excluding revoked peers; reload service.

**4.4.4 Node Status (`node-status.sh`)**
1. Report: systemd service status for Xray/sing-box/proxy, uptime, active connection count (from engine stats API if enabled), CPU/RAM load, disk usage.

### 4.5 Multi-Node Design
- Each node is independently bootstrapped via `install.sh` with its own domain/IP and REALITY keypair.
- `peers.json` is currently per-node (v1.0); a future version MAY centralize peer management via a small control-plane service that pushes peer lists to each node (out of scope for v1.0, noted as an extension point).

---

## 5. Client-Side Design (MVPN-Client)

### 5.1 Architecture Pattern: Shared Core + Native/Cross-Platform Shell

To satisfy NFR-POR-01 (portability) while keeping native performance and OS-level networking integration (required for kill-switch/TUN device control, which is OS-specific), the recommended approach is:

- **Core Engine:** A Go-based core embedding `sing-box` (which itself supports VLESS, Trojan, Hysteria2, and more) as a library. Go cross-compiles cleanly to Android (via `gomobile`), iOS (via `gomobile`/Swift bridging), Windows, macOS, and Linux. This single core implements:
  - Config parsing (subscription link → structured config)
  - Protocol connection handling (delegated to sing-box)
  - Fallback/retry logic
  - Traffic statistics collection
  - Kill-switch state machine (start/stop system-level blocking rules)
  - DNS override enforcement

- **UI Shell (per platform):**
  - **Android:** Kotlin, using Android's `VpnService` API to establish the TUN interface, calling into the Go core via `gomobile` bindings.
  - **iOS:** Swift, using `NetworkExtension` (Packet Tunnel Provider) framework, calling into the Go core via `gomobile` bindings.
  - **Windows:** C#/.NET or a lightweight Electron/Tauri-based UI, using the WinTUN driver, calling the Go core via a local IPC bridge (the core runs as a background process/service) or via cgo bindings.
  - **macOS:** Swift, using `NetworkExtension`, similar to iOS pattern (or utun on non-App-Store distribution).
  - **Linux:** Go core runs directly as a local daemon/service managing a TUN device; UI shell can be a lightweight GTK/Qt app or Electron/Tauri app communicating with the daemon over a local socket.

> **Alternative (simpler, faster to ship v1):** Instead of building custom native VPN-service integration on every platform, use **existing well-documented client engines already built for this purpose** — e.g., package `sing-box`'s official mobile/desktop libraries, or adapt an open-source client shell (such as NekoBox/FairVPN-style architectures) as a UI reference, and focus MVPN engineering effort on server design + branding + configuration UX rather than re-implementing OS-level VPN plumbing from zero. This is the **recommended path for v1.0** to reduce risk and time-to-first-working-build; the full custom native integration above becomes the v2.0 target once v1.0 is validated.

### 5.2 Client Module Breakdown

| Module | Responsibility |
|---|---|
| `ConfigManager` | Import/parse/store server configs (subscription link, QR, manual) |
| `ConnectionController` | Orchestrates connect/disconnect, protocol selection, fallback sequencing |
| `TunnelEngine` | Wraps sing-box/Xray core; manages the TUN interface via platform VPN API |
| `KillSwitch` | Monitors tunnel state; applies/removes system-level traffic block rules |
| `DnsGuard` | Forces DNS resolution through the tunnel; detects/blocks leaks |
| `StatsCollector` | Tracks session duration, bytes in/out, latency (ping through tunnel) |
| `SecureStore` | Wraps platform secure storage for credentials |
| `UI Layer` | Status screen, Node list screen, Settings screen |

### 5.3 Client State Machine

```
Disconnected → Connecting → Connected → (drop) → Reconnecting → Connected
                    │                         │
                    └────► ConnectFailed ◄────┘
                                │
                          (fallback protocol)
                                │
                          Connecting (fallback)
```

### 5.4 Kill-Switch Design (per platform notes)
- **Android:** Use `VpnService.Builder.setBlocking(true)` design pattern + route all traffic (0.0.0.0/0) through the TUN interface; if the underlying tunnel process dies, keep the TUN interface up but drop packets (fail closed) until reconnected.
- **iOS/macOS:** Use `NEPacketTunnelProvider` with `includeAllNetworks`/on-demand rules; fail-closed by not tearing down the tunnel provider on transient errors, instead pausing packet flow.
- **Windows:** Adjust WFP (Windows Filtering Platform) rules or route table so that, if the WinTUN interface goes down unexpectedly, a fallback firewall rule blocks all outbound traffic except to the VPN server IP until the tunnel is restored.
- **Linux:** Use `iptables`/`nftables` rules keyed to the tunnel's up/down state, applied/removed by the core daemon.

### 5.5 DNS Leak Protection
- While connected, the client SHALL push DNS server settings (e.g., a DNS resolver reachable only through the tunnel, or a public DoH/DoT resolver reached via the tunnel) as the system's active DNS, and SHALL block any DNS queries attempted outside the tunnel interface.

---

## 6. Admin/Management Design (MVPN-Admin)

### 6.1 v1.0: Script-Based (recommended starting point)
- All admin actions performed via SSH + the scripts described in §4.4.
- Subscription link/QR generation output locally on the server, then securely shared (e.g., via Signal/encrypted channel) to the new client device — never sent through unencrypted channels.

### 6.2 v1.5/v2.0 Extension: Minimal REST API (optional, future)
- A small authenticated API (token-based) exposing: `POST /peers`, `DELETE /peers/{id}`, `GET /peers`, `GET /nodes/status`.
- Would allow a lightweight admin mobile/web dashboard later. Explicitly out of scope for the first working build; the SRS/SDD note this as an extension point only.

---

## 7. Data Design

### 7.1 `peers.json` Schema (per node)

```json
{
  "peers": [
    {
      "id": "uuid-v4-string",
      "label": "owner-phone",
      "protocol": "vless-reality",
      "created_at": "2026-09-01T12:00:00Z",
      "status": "active",
      "secret": "<protocol-specific credential, stored server-side only>"
    }
  ]
}
```

### 7.2 Client Local Config Schema (post-import, conceptual)

```json
{
  "nodes": [
    {
      "name": "Node-1-SG",
      "protocol_primary": "vless-reality",
      "protocol_fallback": "hysteria2",
      "address": "node1.example-domain.com",
      "port": 443,
      "credentials": "<securely stored, referenced by key alias not raw value>"
    }
  ],
  "settings": {
    "kill_switch": true,
    "auto_connect": false,
    "auto_reconnect": true,
    "dns_leak_protection": true
  }
}
```

---

## 8. Deployment Design

### 8.1 Server Deployment Pipeline
1. Provision VPS (manual step, project owner).
2. Point domain DNS A/AAAA record to VPS IP (manual step, project owner).
3. Run `install.sh` (automated bootstrap, §4.4.1).
4. Run `add-peer.sh` for the owner's first device(s).
5. Validate connectivity from a test client on an unrestricted network first, then from within the restrictive network.

### 8.2 Client Build/Release Pipeline (per platform)
- Android: Gradle build → signed APK (or Play Internal Testing track if desired).
- iOS: Xcode build → TestFlight (if Apple Developer account available) or ad-hoc/sideload for personal use.
- Windows: Build installer (e.g., via Inno Setup or MSIX) from compiled binaries.
- macOS: Xcode build → notarized `.app`/`.dmg` (or ad-hoc for personal use).
- Linux: Build AppImage or distro-specific package (`.deb`).

---

## 9. Testing Strategy (Design-Level)

| Test Type | Focus |
|---|---|
| Unit tests | Config parsing, fallback logic, kill-switch state machine |
| Integration tests | Client↔Server handshake success for each protocol (VLESS-REALITY, Trojan, Hysteria2) |
| Network simulation tests | Simulate connection drop → verify kill-switch blocks traffic; simulate primary protocol block → verify fallback triggers |
| Field test (real environment) | Sustained connection test from within the target restrictive network over 24–72 hours, monitoring for disconnects/detection |
| Security review | Verify no plaintext secret storage; verify no destination-content logging server-side |

---

## 10. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Primary protocol/port gets actively blocked over time | Multi-protocol fallback (§3.1–3.3) + ability to rotate node IP/domain |
| Server IP gets blocklisted | Multi-node design (§4.5); ability to spin up a fresh node quickly via `install.sh` |
| Client secret compromise | Platform secure storage (§3.5); per-device peer credentials (easy to revoke individually, FR-ADM-03) |
| Legal/compliance exposure | Restrict use to project owner's own lawful personal use per Concept Note §9; do not operate as a public commercial service without separate legal review |

---
*End of SDD*

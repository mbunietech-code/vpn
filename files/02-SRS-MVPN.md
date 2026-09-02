# SOFTWARE REQUIREMENTS SPECIFICATION (SRS)
## Mbunie VPN (MVPN)

**Document Version:** 1.0
**Standard Reference:** Structured per IEEE 830 / ISO/IEC/IEEE 29148 conventions
**Status:** Draft for Development Handoff

---

## 1. Introduction

### 1.1 Purpose
This document specifies the functional and non-functional requirements for Mbunie VPN (MVPN), a cross-platform VPN system comprising a server component and client applications. It is intended to be sufficient, on its own (together with the accompanying SDD), for a developer or AI coding agent to implement the system without needing further requirements clarification from the product owner.

### 1.2 Scope
MVPN consists of:
- **MVPN-Server**: A Linux server component providing obfuscated VPN/proxy endpoints, peer/client management, and monitoring.
- **MVPN-Client**: Applications for Android, iOS, Windows, macOS, and Linux that connect to MVPN-Server.
- **MVPN-Admin**: A lightweight management interface/CLI for provisioning clients and monitoring server/node health.

Out of scope for v1.0: public self-service signup portal, payment processing, multi-tenant SaaS billing.

### 1.3 Definitions, Acronyms, Abbreviations

| Term | Definition |
|---|---|
| DPI | Deep Packet Inspection — technique used by firewalls to inspect packet contents to identify traffic type |
| Kill-switch | A mechanism that blocks all network traffic if the VPN tunnel disconnects unexpectedly |
| Reality | A TLS camouflage technique (used by Xray-core) that borrows a real website's TLS identity to defeat active probing |
| Node | A single VPN server instance/endpoint |
| Peer | A single client credential/configuration allowed to connect to a node |
| Subscription link | A URL that a client app can import to auto-configure connection settings |
| RTT | Round-trip time (latency) |
| MTU | Maximum Transmission Unit |

### 1.4 References
- IEEE 830-1998 (SRS structure)
- Xray-core documentation (VLESS, VMess, Trojan, REALITY)
- sing-box documentation (Shadowsocks, Hysteria2, TUIC, WireGuard)
- RFC 8446 (TLS 1.3)

### 1.5 Overview
Section 2 gives an overall description of the product. Section 3 specifies detailed functional requirements. Section 4 specifies non-functional requirements. Section 5 covers external interface requirements. Section 6 covers system features by module. Section 7 covers constraints. Section 8 covers appendices (use cases).

---

## 2. Overall Description

### 2.1 Product Perspective
MVPN is a new, independent, self-hosted system. It is not a plugin or extension of an existing product. It has three primary subsystems: Server, Client, and Admin/Management tooling, communicating over well-defined protocols (VLESS/Trojan/Hysteria2 over TLS, and a lightweight REST API for management).

### 2.2 Product Functions (Summary)
- Establish and maintain an encrypted, obfuscated tunnel between client and server.
- Route all (or selected) client device traffic through the tunnel.
- Protect against traffic leaks (DNS leak, IP leak) if the tunnel drops.
- Allow the user to select between multiple server nodes.
- Allow the administrator (project owner) to add/remove/rotate client credentials.
- Provide visibility into connection health and data usage.

### 2.3 User Classes and Characteristics

| User Class | Description | Technical Level |
|---|---|---|
| End User | Uses the MVPN-Client app to connect/disconnect and browse securely | Low–medium |
| Administrator | Provisions server(s), manages client credentials, monitors nodes | High (this is the project owner / the implementing engineer) |

### 2.4 Operating Environment
- **Server:** Linux (Ubuntu 22.04/24.04 LTS or Debian 12), deployed on a cloud VPS outside the restrictive network jurisdiction.
- **Clients:** Android 8+, iOS 14+, Windows 10+, macOS 12+, major Linux desktop distros (Ubuntu/Debian/Fedora derivatives).

### 2.5 Design and Implementation Constraints
- Must use only protocols/tools with active open-source maintenance and public documentation (e.g., Xray-core, sing-box).
- Must not depend on any single third-party VPN vendor/service.
- Server-side camouflage (fake website) must serve real, valid content over TLS on the same port as the VPN endpoint.
- Client apps must not store plaintext credentials on disk; secrets must be stored in platform-appropriate secure storage (Keychain / Keystore / Credential Manager / encrypted local store).

### 2.6 Assumptions and Dependencies
- A domain name and cloud VPS(s) will be provisioned by the project owner before server implementation begins.
- DNS for the chosen domain is under the project owner's control.
- The implementing team/agent has access to standard package managers and build toolchains (Go, Node.js/Flutter or native SDKs as chosen in the SDD).

---

## 3. Functional Requirements

Each requirement has an ID for traceability: `FR-<module>-<number>`.

### 3.1 Server Module (FR-SRV)

- **FR-SRV-01**: The server SHALL run a VPN/proxy engine (Xray-core and/or sing-box) exposing at least one obfuscated inbound protocol (VLESS+REALITY or Trojan-over-TLS as primary).
- **FR-SRV-02**: The server SHALL support at least one fallback/secondary protocol (e.g., Hysteria2 over QUIC) for cases where the primary protocol is blocked.
- **FR-SRV-03**: The server SHALL serve a real, innocuous website (static site or reverse-proxied content) on the same TLS port used for VPN camouflage, such that non-VPN requests receive a normal HTTP response.
- **FR-SRV-04**: The server SHALL support adding, disabling, and removing individual client peers/credentials without restarting the entire service (hot reconfiguration where the underlying engine supports it, or a scripted reload otherwise).
- **FR-SRV-05**: The server SHALL log connection metadata required for operational health (connect/disconnect events, error rates) WITHOUT logging the content of user traffic or visited destinations, consistent with a no-content-logging policy.
- **FR-SRV-06**: The server SHALL expose a method (script or lightweight API) to generate a client-ready configuration (JSON config and/or subscription link and/or QR code) for a newly added peer.
- **FR-SRV-07**: The server SHALL support running multiple independent nodes (geographically distinct) that can be added to the client's node list.
- **FR-SRV-08**: The server SHALL support automatic TLS certificate provisioning and renewal (e.g., via Let's Encrypt/ACME) for any non-REALITY deployment mode.
- **FR-SRV-09**: The server installation/setup process SHALL be scripted (Bash and/or Ansible) to allow repeatable deployment to a fresh VPS.
- **FR-SRV-10**: The server SHALL support basic firewall hardening (only required ports open; SSH access restricted/hardened).

### 3.2 Client Module (FR-CLI)

- **FR-CLI-01**: The client SHALL allow the user to import a configuration via subscription link, QR code, or manual paste.
- **FR-CLI-02**: The client SHALL provide a one-tap/one-click Connect and Disconnect action.
- **FR-CLI-03**: The client SHALL display current connection status (Connected/Disconnected/Connecting/Error) at all times.
- **FR-CLI-04**: The client SHALL support selecting between multiple configured server nodes.
- **FR-CLI-05**: The client SHALL implement a kill-switch: when enabled, if the VPN tunnel unexpectedly drops, all non-VPN network traffic SHALL be blocked until the tunnel is restored or the user disables the VPN.
- **FR-CLI-06**: The client SHALL implement DNS leak protection: all DNS queries SHALL be routed through the encrypted tunnel while connected.
- **FR-CLI-07**: The client SHALL automatically attempt reconnection on unexpected disconnects or network interface changes (e.g., Wi-Fi to mobile data).
- **FR-CLI-08**: The client SHALL display basic session statistics: connection duration, current node, approximate latency, and data transferred (upload/download).
- **FR-CLI-09**: The client SHALL allow protocol auto-fallback: if the primary protocol fails to connect after N attempts/timeout, the client SHALL automatically attempt the configured fallback protocol.
- **FR-CLI-10**: The client SHALL persist the last-used server/node selection and reconnect to it on next app launch (with user-configurable auto-connect-on-launch).
- **FR-CLI-11**: The client SHALL store all connection secrets/keys using the platform's secure storage mechanism (Android Keystore, iOS Keychain, Windows Credential Manager/DPAPI, macOS Keychain, Linux Secret Service/encrypted file with restricted permissions).
- **FR-CLI-12**: The client SHALL provide a settings screen to toggle: kill-switch, auto-connect, auto-reconnect, and protocol preference/fallback order.
- **FR-CLI-13**: The client UI SHALL be consistent in core functionality (connect/disconnect/status/node-select/settings) across all supported platforms, with platform-appropriate UI conventions.

### 3.3 Admin/Management Module (FR-ADM)

- **FR-ADM-01**: The administrator SHALL be able to add a new client peer via a single command or API call, producing a ready-to-import client configuration.
- **FR-ADM-02**: The administrator SHALL be able to list all active peers and their basic usage stats (connected/last-seen, approximate data used).
- **FR-ADM-03**: The administrator SHALL be able to revoke/disable a peer's access immediately.
- **FR-ADM-04**: The administrator SHALL be able to view basic node health (uptime, CPU/RAM load, active connection count) for each server node.
- **FR-ADM-05**: The administrator SHALL be able to rotate the REALITY/TLS keys or certificates for a node without needing to reconfigure every client manually (i.e., changes should be distributable via updated subscription link).

---

## 4. Non-Functional Requirements

### 4.1 Performance
- **NFR-PERF-01**: Under normal network conditions, tunnel overhead SHOULD add no more than ~15–20% latency compared to direct connection.
- **NFR-PERF-02**: The client SHALL establish a connection (from tap/click to "Connected" state) in under 5 seconds under normal conditions.

### 4.2 Security
- **NFR-SEC-01**: All tunneled traffic SHALL be encrypted using modern, currently-unbroken ciphers (TLS 1.3; ChaCha20-Poly1305 or AES-256-GCM at the transport/protocol layer as applicable).
- **NFR-SEC-02**: Private keys and credentials SHALL never be transmitted or stored in plaintext at rest on the client.
- **NFR-SEC-03**: The server SHALL NOT log destination URLs, DNS queries, or payload content of user traffic.
- **NFR-SEC-04**: SSH access to the server SHALL be key-based only (password authentication disabled) after initial setup.
- **NFR-SEC-05**: The system SHALL be designed so that a passive network observer cannot distinguish MVPN traffic from ordinary TLS/HTTPS traffic based on handshake fingerprint alone.

### 4.3 Reliability & Availability
- **NFR-REL-01**: The client SHALL automatically attempt to reconnect up to a configurable number of times before surfacing a persistent error to the user.
- **NFR-REL-02**: The server process SHALL be supervised (e.g., via `systemd`) to auto-restart on crash.

### 4.4 Usability
- **NFR-USA-01**: A non-technical user SHALL be able to go from "app installed" to "connected" in 3 steps or fewer (import config → tap connect → connected).

### 4.5 Portability
- **NFR-POR-01**: The client core logic SHALL be structured to maximize code reuse across platforms (shared core library/module, platform-specific UI shell).

### 4.6 Maintainability
- **NFR-MAI-01**: Server deployment SHALL be fully reproducible via scripts/IaC, such that a new node can be stood up in under 15 minutes.
- **NFR-MAI-02**: Code SHALL be organized into clearly separated modules per the SDD, with inline documentation for all public functions/interfaces.

---

## 5. External Interface Requirements

### 5.1 User Interfaces
- Mobile and desktop apps with: Home/Status screen, Node Selection screen, Settings screen, (optional) Stats/Logs screen.

### 5.2 Hardware Interfaces
- Standard network interfaces (Wi-Fi, mobile data, Ethernet) on client devices; standard virtual network interface (TUN device) for system-wide traffic routing.

### 5.3 Software Interfaces
- Client-to-server: VLESS/Trojan/Hysteria2 protocol over TLS/QUIC.
- Admin tooling-to-server: local CLI/script execution over SSH, or a minimal authenticated REST API (if implemented) secured with token-based auth over HTTPS.

### 5.4 Communications Interfaces
- All control communications (subscription link fetch, admin API) SHALL occur over HTTPS/TLS.

---

## 6. System Features by Module (Traceability Summary)

| Module | Related FRs | Related NFRs |
|---|---|---|
| Server | FR-SRV-01 → FR-SRV-10 | NFR-SEC-01, 03, 04, 05; NFR-REL-02; NFR-MAI-01 |
| Client | FR-CLI-01 → FR-CLI-13 | NFR-PERF-01, 02; NFR-SEC-01, 02; NFR-REL-01; NFR-USA-01; NFR-POR-01 |
| Admin | FR-ADM-01 → FR-ADM-05 | NFR-MAI-01, 02 |

---

## 7. Other Constraints

- Legal/regulatory compliance is the responsibility of the project owner; the implementing developer/agent SHALL restrict itself to standard, publicly documented VPN/proxy engineering (no novel exploit development, no targeting of third-party infrastructure).
- Budget/infrastructure: number of nodes and their specs to be determined by the project owner (see SDD §4 for recommended minimum specs).

---

## 8. Appendix — Primary Use Cases

**UC-1: First-time Connect**
1. User installs MVPN-Client.
2. User imports configuration (QR/link/paste) received from Administrator.
3. User taps "Connect".
4. Client establishes tunnel, status changes to "Connected".
5. User's traffic is now routed through MVPN.

**UC-2: Tunnel Drop While Kill-Switch Enabled**
1. User is connected; network briefly drops (e.g., switching Wi-Fi networks).
2. Client detects disconnection.
3. Kill-switch blocks all non-VPN traffic immediately.
4. Client attempts auto-reconnect.
5. On success, kill-switch releases and traffic resumes normally; on repeated failure, user is notified.

**UC-3: Administrator Adds a New Peer**
1. Administrator runs peer-add script/command on the server (or via Admin API).
2. Server generates new credentials and a subscription link/QR code.
3. Administrator shares the link/QR with the new client device.
4. New device imports and connects (see UC-1).

**UC-4: Administrator Revokes a Peer**
1. Administrator identifies a peer to revoke (e.g., lost device).
2. Administrator runs revoke command.
3. Server immediately rejects further connections from that peer's credentials.

---
*End of SRS*

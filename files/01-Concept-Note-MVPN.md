# CONCEPT NOTE
## Mbunie VPN (MVPN)

**Document Version:** 1.0
**Prepared for:** Mbunie Tech
**Document Type:** Project Concept Note
**Status:** Draft for Development Handoff

---

## 1. Executive Summary

Mbunie VPN (MVPN) is a cross-platform, privacy-focused Virtual Private Network (VPN) system consisting of a self-hosted server component and native/cross-platform client applications. MVPN is designed with a primary focus on **censorship-resistant connectivity**, enabling reliable, low-latency, and difficult-to-detect encrypted tunneling in restrictive network environments (e.g., networks that employ Deep Packet Inspection (DPI), active probing, and protocol fingerprinting to detect and block conventional VPN traffic).

The product is being built for personal/private use by the project owner, with an architecture that can later scale to serve a small trusted user base if desired.

## 2. Problem Statement

Conventional VPN protocols (plain OpenVPN, plain WireGuard, first-generation Shadowsocks) are increasingly detectable and blockable by advanced national-level firewalls through:

- Deep Packet Inspection (DPI) fingerprinting of handshake patterns
- Active probing of suspected VPN server IPs/ports
- Traffic pattern / statistical analysis (packet timing, size distributions)
- IP/domain blocklisting of known VPN infrastructure providers

There is a need for a VPN system whose traffic is **indistinguishable from ordinary HTTPS traffic** to a passive or active observer, while still being fast, stable, and easy to operate across devices (mobile and desktop).

## 3. Vision

To build a private, professional-grade VPN platform — **Mbunie VPN (MVPN)** — that:

1. Disguises VPN traffic as legitimate HTTPS/web traffic
2. Resists active probing and DPI-based detection
3. Provides a consistent, reliable user experience across Android, iOS, Windows, macOS, and Linux
4. Is fully owned and operated by Mbunie Tech (self-hosted infrastructure, no third-party VPN dependency)
5. Follows professional software engineering practices (proper SRS, SDD, testing, and documentation)

## 4. Objectives

- **O1:** Build a VPN server (Linux-based) using a modern obfuscated protocol stack capable of resisting DPI/censorship in restrictive network environments.
- **O2:** Build a unified client core (shared logic) that can be deployed on Android, iOS, Windows, macOS, and Linux.
- **O3:** Provide a simple, clean user interface for connect/disconnect, server selection, and connection status.
- **O4:** Ensure strong security: modern encryption, no logging of user traffic content, secure key management.
- **O5:** Ensure operational resilience: automatic reconnection, kill-switch, DNS leak protection.
- **O6:** Enable easy multi-server / multi-node management for future scaling.
- **O7:** Produce full professional documentation (SRS, SDD) so the system can be built, maintained, and extended by any competent developer or AI coding agent.

## 5. Target Use Case

- **Primary user:** Project owner (Mbunie Tech), using the VPN personally while operating from within a country with strict internet censorship (network environment with active DPI/blocking of VPN protocols).
- **Secondary/future use case:** A small number of trusted users (friends, team, or clients) added to the same private VPN infrastructure.

This is **not** intended, at this stage, as a public commercial VPN product with open user registration, though the architecture should not preclude that evolution.

## 6. Key Differentiators

| Differentiator | Description |
|---|---|
| **DPI Resistance** | Uses TLS-disguised protocols (e.g., VLESS+Reality / Trojan-over-TLS / Hysteria2) instead of easily-fingerprinted legacy protocols |
| **Self-hosted** | Full control of server infrastructure — no reliance on third-party VPN companies |
| **Cross-platform** | One consistent product experience across mobile and desktop |
| **Fail-safe design** | Kill-switch and DNS-leak protection built in from day one |
| **Professional engineering** | Built from a full SRS/SDD, not an ad-hoc script |

## 7. High-Level Feature Set

1. One-tap Connect/Disconnect
2. Server/node selection (multiple regions/nodes)
3. Auto-reconnect on network change or drop
4. Kill-switch (block all traffic if VPN drops)
5. DNS leak protection (force DNS through tunnel)
6. Connection stats (latency, data used, uptime)
7. Protocol auto-fallback (e.g., try Reality → fallback to Hysteria2 if blocked)
8. Config import/export (QR code / subscription link for easy client setup)
9. Multi-device support per account/key
10. Admin/server-side: client (peer) management, traffic accounting, node health monitoring

## 8. High-Level Technology Direction

- **Core VPN/proxy engine:** `Xray-core` (VLESS/VMess/Trojan + Reality) and/or `sing-box` (unified support for Shadowsocks, VMess, VLESS, Trojan, Hysteria2, TUIC, WireGuard)
- **Transport obfuscation:** TLS 1.3 disguised as HTTPS, optionally with **REALITY** (borrows a real website's TLS certificate identity to defeat active probing) or CDN-fronting
- **Server OS:** Ubuntu/Debian Linux (cloud VPS)
- **Reverse proxy / web camouflage:** Nginx or Caddy serving a real-looking website on the same port as the VPN endpoint
- **Client apps:** Cross-platform via a shared core (Go-based `sing-box`/`Xray` library) with native or Flutter/React Native UI shells per platform
- **Management/automation:** Scripted server provisioning (Bash/Ansible), a lightweight backend API for client provisioning and node management

## 9. Constraints & Assumptions

- **Constraint:** Server(s) must be hosted **outside** the restrictive country, on providers/IP ranges not already broadly blocked.
- **Constraint:** Domain names used must have real, valid TLS certificates (e.g., via Let's Encrypt) to support convincing HTTPS camouflage.
- **Assumption:** The project owner will provide/purchase cloud VPS resources and a domain name.
- **Assumption:** Initial rollout targets a small number of devices/users (not a public-scale service).
- **Legal note:** VPN usage and provisioning is subject to the laws of the jurisdictions involved (both the server's hosting country and the user's location). This project is intended strictly for lawful personal privacy and secure communication use. The build team/AI agent should not be asked to implement anything beyond standard, publicly-documented VPN/proxy technology.

## 10. Success Criteria

- MVPN client can establish and maintain a stable connection from within a restrictive network environment for extended periods (hours/days) without manual intervention.
- Traffic is not identified/blocked by standard DPI over a sustained testing period.
- Client apps run on at least: Android, Windows, and one additional platform (iOS or macOS or Linux) in the first release.
- Full SRS and SDD documents exist and are sufficient for a developer/AI agent to build the system without further clarification from the product owner.

## 11. Deliverables of This Documentation Package

1. **Concept Note** (this document)
2. **Software Requirements Specification (SRS)**
3. **System/Software Design Document (SDD)**
4. **Full Build Instructions** for the implementing developer/AI agent

---
*End of Concept Note*

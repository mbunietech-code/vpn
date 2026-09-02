# DESIGN SYSTEM — MVPN Flutter Client

**Version:** 1.0
**Date:** 2026-09-02
**Source:** Owner's Stitch mockups (Home, Servers, Session Stats, Settings) + supplied palette
**Applies to:** Flutter app (Android, Windows, macOS, Linux) — `client/`

The owner supplies **pattern + colours only**. Screens below are rebuilt natively in Flutter, not exported from Stitch.

---

## 1. Colour tokens

### 1.1 Light (primary theme — matches mockups)
| Token | Hex | Use |
|---|---|---|
| `brand` | `#2563EB` | Primary actions, active nav, toggles ON, links, connected ring, key figures |
| `brandDark` | `#1D4ED8` | Pressed state, gradients |
| `brandAccent` | `#3B82F6` | Charts, secondary highlights, signal bars |
| `bg` | `#F7F9FC` | App / scaffold background |
| `surface` | `#FFFFFF` | Cards, sheets, nav bar |
| `surfaceAlt` | `#F4F6FA` | Inset fills (list rows, chips, input fields) |
| `border` | `#EEF1F6` | Card borders, dividers |
| `textPrimary` | `#1A2233` | Titles, primary copy |
| `textSecondary` | `#6B7688` | Sub-labels, descriptions |
| `textHint` | `#9AA4B2` | Section captions (ALL-CAPS), placeholder, inactive nav |
| `stateIdle` | `#B0B7C3` | Large "Disconnected" text, idle connect glyph |
| `success` | `#16A34A` | "Good" quality, healthy signal, connected status text |
| `warning` | `#F59E0B` | Degraded node, medium latency |
| `danger` | `#DC2626` | Errors, kill-switch active banner, revoke actions |

### 1.2 Dark (secondary theme)
| Token | Hex |
|---|---|
| `brand` | `#3B82F6` |
| `bg` | `#0E1420` |
| `surface` | `#161D2B` |
| `surfaceAlt` | `#1E2635` |
| `border` | `#252E3F` |
| `textPrimary` | `#EEF2F8` |
| `textSecondary` | `#9AA6B8` |
| `textHint` | `#6B7688` |
| `stateIdle` | `#3A4456` |
| success / warning / danger | `#22C55E` / `#FBBF24` / `#F87171` |

---

## 2. Shape, elevation, motion
- **Radius:** cards `16`, buttons/inputs `12`, chips & toggles `full`, connect button `full` (circle).
- **Elevation:** near-flat. Cards = `surface` + `1px border` + shadow `0 1px 3px rgba(16,24,40,0.04)`. Bottom sheets get `0 -4px 24px rgba(16,24,40,0.08)`.
- **Connect button:** circle ~`160dp`, `3dp` ring. Idle = `stateIdle` ring + glyph on `surfaceAlt`. Connecting = `brandAccent` ring, indeterminate sweep. Connected = `brand` ring + soft outer glow, filled power glyph.
- **Motion:** 200ms `easeOutCubic` for state changes; connect/disconnect ring 350ms.

---

## 3. Typography
System font stack (Roboto / SF / Segoe). Scale:
| Style | Size / weight |
|---|---|
| Display (duration `02:14:36`) | 40 / w700, tabular figures, `brand` |
| Title (app bar, "Settings") | 20 / w600 |
| Section caption | 12 / w600, letter-spacing 0.8, UPPERCASE, `textHint` |
| Body | 15 / w500 (`textPrimary`) |
| Sub-label | 13 / w400 (`textSecondary`) |
| Metric label ("DOWNLOADED") | 11 / w600, UPPERCASE, `textHint` |
| Metric value ("14.2 GB") | 18 / w700 (`textPrimary`) |

---

## 4. Core components
- **BottomNav:** 3 items — Home, Servers, Settings. White `surface`, top `1px border`, active icon+label `brand`, inactive `textHint`. Filled icon when active, outline when not.
- **NodeRow:** flag/badge circle, name + code (`US-EAST-01`), right side latency `24ms` + 4-bar signal (green/amber by latency: <80 green, <160 amber, else grey). Selected row = `brandAccent` left border + tint.
- **OptimalServerCard:** lightning glyph, "Optimal Server / Auto-connect to fastest node", full-width, `surfaceAlt`.
- **SettingRow:** title + sub-label left, control right (Switch, or chevron, or dropdown). Grouped under section captions inside a single card.
- **Switch:** track `brand` when ON, `#D5DBE4` when OFF; white thumb.
- **StatTile:** metric label + value, optional trailing round icon button.
- **ThroughputChart:** area chart, DOWN = `brandAccent` fill @12%, UP = `success` line; no gridlines, faint baseline only.
- **Buttons:** primary = filled `brand`, radius 12, 48dp tall, w600. Secondary = `surface` + `border`. Ghost = text only `brand`.

---

## 5. Screen inventory (v1)
| Screen | Key elements | Backed by |
|---|---|---|
| **Onboarding / Auth** | logo, phone/email + OTP, "Continue" | `POST /api/auth/*` |
| **Plans** | plan cards, each shows `¥28 / $3.99`, duration, devices; "Choose" | `GET /api/plans` |
| **Checkout** | method picker (Alipay / WeChat / Card / Crypto), opens hosted page, then "Waiting for payment…" spinner | `POST /api/checkout`, poll `GET /api/subscription` |
| **Home** | app title + logo, big status word, "IP Obfuscated" shield, circular Connect, Current Node card ("Change"), mini session stats | core state + `GET /api/subscription` |
| **Servers** | "Select Node", Ping toggle, Optimal card, grouped node list by region | `/sub/{token}` parsed → nodes |
| **Session Stats** | node + "Secure Connection Active", big duration, throughput chart, downloaded/uploaded, peak, protocol | sing-box stats API |
| **Settings** | CONNECTION (kill-switch, auto-connect, protocol preference), ACCOUNT & PEER (peer id + copy, import config), ABOUT (version, ToS, Privacy) | local prefs + account |

Protocol Preference options shown to user: **Auto**, **VLESS-REALITY**, **Hysteria2**. (Mockup's "WireGuard"/"VLESS+REALITY" strings are placeholders — real set is these three.)

---
*End of Design System v1.0*

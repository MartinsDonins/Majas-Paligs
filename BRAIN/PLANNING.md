# PLANNING — Mājas Palīgs

> Authoritative AI context. Read at the start of every session.

## 1. Vision

A simple, secure web app (PWA) for residents of Latvian multi-apartment buildings
(daudzdzīvokļu mājas) to organise themselves: see the building as a virtual model,
report problems per apartment / riser, keep shared registries (parking, contacts),
and turn chat noise into structured data that can be handed to the building manager.

- **Pilot**: Puces iela 51, Rīga — series 602, 9 floors, 5 stairwells, 180 apartments,
  managed by SIA "Rīgas namu pārvaldnieks" (RNP). Pilot stairwell: No. 5 (apts 145–180).
- **Product goal**: multi-building SaaS offered to other buildings later (per-building
  tenancy, building series catalogue reused across buildings).
- **Current priorities**: 🚗 parking registry, 🔥 heating map.

## 2. Problems observed (source: stairwell 5 WhatsApp chat, 2025-08 → 2026-10)

Raw chat lives in `DOC/` (git-ignored — contains personal data, never commit or quote numbers).

| Area | Evidence |
|---|---|
| Parking | ~60 spaces for 180 apts; apartments with 2+ permits; guest permits used as permanent; many foreign cars. Since 2026-04-01 the manager/land owner must give police a list of resident plates — collected by hand, incomplete. |
| Heating / water | Some radiators cold, others warm in same apartment; outages without notice; residents ask "do you have water?" each time; nobody knows which riser is affected. |
| Information flow | New chat members cannot see history → BIS / voting instructions reposted again and again. LV + RU mixed. Contact/BIS tracking in a half-filled Google Sheet. |
| Decisions | RNP contract vote, mandatory BIS registration (with e-mail), water meter system decision due **2027-01-01**, water loss difference ≈ 4 €/month per apt. |
| Security | Door codes written on walls repeatedly; strangers sleeping on 9th floor; camera idea needs GDPR answer. |
| Manager requests | Broken code lock, lights, lift — "more requests = faster fix", but no one tracks who already reported. |
| Land | Building sits on two cadastral parcels; residents want functional plot revised. Long-term document topic. |

## 3. Tech stack (DECIDED 2026-10-17, pending owner confirmation)

Owner works in both Laravel and Next.js. **Laravel chosen** — deviation from the global
TypeScript baseline is intentional (owner's call):

- **Laravel (latest stable) + PHP 8.4**, single codebase (no separate API service).
- **Livewire + Alpine.js + Tailwind** for the resident UI (mobile-first PWA, installable, no app store).
- **Filament** for admin / building-setup panel (series catalogue, building generator, registries).
- **PostgreSQL** (JSONB for series templates, good indexing).
- **Redis** for queues / cache / rate limiting.
- **Deploy**: Docker on Coolify, auto-deploy from `main`.
- **Errors**: GlitchTip / Sentry SDK.
- **i18n**: LV (default) + RU from day 1; EN later.

Why Laravel over Next.js for this product:
- Auth, policies/gates, CSRF, rate limiting, encrypted casts, queues, mail, notifications,
  signed URLs, audit-friendly Eloquent — all built-in → less custom security code.
- One language, one deployable, boring upgrades → easier to maintain alone.
- Next.js App Router/RSC had several critical advisories in 2025 (middleware auth bypass,
  RSC remote code execution) — fast-moving surface that needs constant patching.
- Filament gives a production-grade admin for free.

`frontend/mobile/` (Expo) stays reserved per global rules but is NOT planned — the PWA covers mobile.

## 4. Architecture

### 4.1 Tenancy
- Single database, row-level tenancy by `building_id` (global scope + policy checks on every query).
- Optional `organization` above buildings (residents' association / biedrība, or later a manager company).
- Every tenant-scoped model has a test asserting it cannot be read across buildings.

### 4.2 Building Series Catalogue (key product idea)
Latvian Soviet-era series (602, 467, 119, 103, 104, 316, 318, Lithuanian "LT", etc.) repeat
the same layout. Model a **series template once**, generate any building of that series in seconds.

```
building_series            (code "602", name, floors_typical, notes, source)
 └ series_section_layouts  (apartments per floor, positions 1..n, left/right side)
    └ series_apartment_types (position → rooms count, area, room list)
       └ series_rooms        (room code: kitchen, bath, room1…, radiator count)
 └ series_risers           (riser code, kind: heating|cold|hot|sewage|gas,
                            which positions/rooms it serves, vertical through all floors)
```

Building generator: choose series → number of stairwells → floors → first apartment number
→ numbering direction → generates `sections`, `apartments`, `rooms`, `radiators`, `risers`.
Per-building overrides allowed (renovations, merged apartments).
Corrections made by a building can be **proposed back to the series template**
(curated by platform admin) → every new building of that series gets a better riser map.

Pilot fact: series 602 here = 4 apartments/floor; apartment directly above = number + 4
(e.g. 145 → 149 → … → 177 form one vertical column).

### 4.3 Core domain
```
buildings (address, cadastre, series_id, manager_name, settings)
sections (stairwell no., first_apartment_no)
apartments (number, floor, position, section_id, type_id)
rooms / radiators (room → radiator → riser_id)
risers (building_id, section_id, code, kind)
users ─ apartment_memberships (role: owner | tenant | contact; verified_at)
building_roles (platform_admin | building_admin | section_elder | helper)
audit_log (who viewed / changed sensitive data)
```

### 4.4 Module: 🔥 Heating map (MVP)
- Grid view: floors × positions per stairwell, coloured by aggregated status.
- Resident reports per room/radiator: `warm | lukewarm | cold | overheated` (+ optional °C, note).
- Reports belong to a **survey round** (e.g. "2026-11 heating check") so data is comparable;
  ad-hoc reports also allowed, valid for N days.
- Aggregation per **riser**: one report per riser is enough to colour the whole column;
  conflicting reports flagged.
- Same grid reused for **outages** ("no cold water now") → shows affected risers live.
- Export: PDF/CSV report for the manager (riser, floors, dates, counts) to request balancing.

### 4.5 Module: 🚗 Parking registry (MVP)
- `parking_lots` (capacity, signage, notes), `permits` (number, type main|guest, apartment,
  valid_from/to, status), `vehicles` (plate, apartment, permit_id).
- Plates: **encrypted at rest** + HMAC **blind index** for exact lookup.
- Plate lookup by any verified resident → only "registered in this building: yes/no".
  Apartment shown only to building_admin / section_elder; every reveal goes to `audit_log`.
- Sightings: resident reports foreign car (plate, photo, time, lot). Repeat offenders flagged.
  Photos auto-deleted after 30 days.
- Analytics: permits per apartment, guest permits with constant plate, plates without permit.
- Export: police / manager list (plates of residents with permits) in required format.

### 4.6 Onboarding & auth
- Per-apartment **invite code** printed and dropped in the mailbox (proves physical access;
  avoids "public QR on the door lets strangers in").
- Login: e-mail magic link (default) + optional password; SMS later (cost).
- Section elder approves membership; owner vs tenant distinguished.
- Rate limiting on code redemption and lookups.

## 5. Security & GDPR baseline
- OWASP Top 10: Laravel defaults + policies on every action, no mass assignment, CSP headers,
  secure cookies, signed URLs for exports.
- Data minimisation: plates and phones only where needed; no names required.
- Encryption at rest for plates, phones, e-mails of contacts.
- Retention: sightings photos 30 d, heating ad-hoc reports 1 heating season, audit log 2 y.
- User data export + deletion (self-service).
- Legal basis & privacy notice in LV/RU; building admin signs a processing agreement (later, for SaaS).
- Cameras: out of scope for the app — only a GDPR guidance page.

## 6. Roadmap

| Phase | Content |
|---|---|
| 0 Foundation | Laravel skeleton, Docker/Coolify, auth, tenancy, roles, LV/RU, series catalogue + building generator (seed: 602 / Puces 51) |
| 1 MVP | 🔥 Heating map + outages · 🚗 Parking registry · pilot in stairwell 5 |
| 2 Info hub | Announcements board (separate from chat), "new neighbour" page, contacts & BIS registration tracker, manager request tracker ("5 apartments reported"), official-vote deadline reminders |
| 3 Product | Multi-building self-signup, series template contributions, anonymous series benchmarks (heating complaints, water loss per series), billing |
| 4 Extras | Water loss tracker from monthly bills (2027 meter decision helper), document vault (contracts, land, protocols) with AI summaries LV/RU, meeting organiser (agenda, proxy forms, protocol), lift/door-code incident log, lost & found / neighbour help, renovation readiness checklist, Telegram/WhatsApp notifications |

## 7. Open questions
- Pilot scope: stairwell 5 only, or all 5 stairwells from start?
- Riser layout of series 602 per room — get floor plans from BIS house file (mājas lieta).
- Who are the building_admin and section elders for the pilot (likely elder of stairwell 5, apt 148)?
- Will the parking permit issuer cooperate (existing permit list as seed data)?
- Domain name / product name.

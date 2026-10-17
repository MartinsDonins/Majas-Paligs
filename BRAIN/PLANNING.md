# PLANNING — Mājas Palīgs

> Authoritative AI context. Read at the start of every session.
> Last full revision: 2026-10-17 (lead-architect pass over the 10-persona team panel).
> Revision 2 (2026-10-17): Karen quality-gate fixes B1–B5 — sanctioned cross-building queries (§4.2),
> DB roles + immutable audit (§4.1, §5), heating reporter privacy (§4.6), two-level parking gate (§4.7),
> PIN brute-force limits (§4.5) — plus 15 review suggestions folded into MVP-SPEC / DESIGN-SYSTEM.
> Revision 3 (2026-10-17): Karen gate 2 fixes — B1 tenancy context allow-list (`CurrentBuilding::set()` callers,
> Filament `getTenants`/`canAccessTenant`, middleware order `SetCurrentBuilding → member` with 404, §4.2) and
> B2 core processing gate **L-core** + split controllership (§5) — plus 17 suggestions (PIN global limiter
> alert-only, secrets out of URLs/logs, audit-table grants, DB bootstrap, vehicle removal flow, registration
> oracles, sighting quotas, no offline queue in MVP, section_ids integrity, export stripping, ext-sodium,
> log retention, WSL2 UID mapping, owner sign-offs).
> Detailed specs: `BRAIN/docs/MVP-SPEC.md` (build packages A/B/C), `BRAIN/docs/DESIGN-SYSTEM.md`,
> `BRAIN/docs/BUSINESS.md`. Tasks: `BRAIN/TASK.md`. Human blockers: `BRAIN/MANUAL-TODO.md`.

## 1. Vision

A neutral **"house operating layer"** for residents of Latvian multi-apartment buildings
(daudzdzīvokļu mājas): a mobile-first PWA in **Latvian and Russian** that turns chat noise
("do you have water?", "whose car is this?") into structured, riser-aware, de-duplicated data
that residents, the section elder and the building manager can act on.

Positioning rules (from the manager, critic and security personas):
- **Not** an anti-manager weapon, **not** a chat, **not** a voting system, **not** BIS, **not** an
  emergency channel. Factual language, aggregates instead of names, no shaming.
- The app **prepares, tracks and exports**; legally binding decisions stay with the owners'
  community (kopība) under the Apartment Ownership Law (Dzīvokļa īpašuma likums) and in BIS.
- Data belongs to the **house**, not to the manager (the pilot house may change manager).

### 1.1 Pilot
- **Pūces iela 51, Rīga, LV-1082** (spelling "Puces" must also match). Series **602**, 9 floors,
  **5 stairwells, 180 apartments**, 4 apartments per floor, numbering 1..180 continuous
  (stairwell *s* starts at `(s-1)*36 + 1`; stairwell 5 = 145–180). Apartment directly above = number + 4
  within the stairwell (145 → 149 → … → 177 is one vertical column).
- Public facts (aggregator data, verify with VZD/kadastrs.lv): address code 101879497, building
  cadastre designation 01000710114001, land parcel likely 0100 071 0114 plus a second parcel
  (stairwells 4–5 on one parcel, 1–3 on another), year ~1987 (unverified), energy class F,
  central heating from Rīgas Siltums. Manager: SIA "Rīgas namu pārvaldnieks" (RNP).
- **Scope = whole building** (owner decision 2026-10-17). All 180 apartments are generated and
  invite codes printed for all; **active outreach wave 1 = stairwell 5**, waves 2–3 = other stairwells
  as soon as each has an elder or floor captain.

### 1.2 Product goal
Multi-building SaaS sellable to any Latvian building. The **building series catalogue** is the
moat: model a series once (602, then 104, 467, 119, 103, …) and every same-series building gets a
riser-map preview in seconds; corrections flow back to the template.

## 2. Problems observed (source: stairwell 5 WhatsApp chat, 2025-08 → 2026-10)

Raw chat lives in `DOC/` (git-ignored — contains personal data; never commit, quote, seed or test with it).

| Area | Evidence (redacted) |
|---|---|
| Water / heating | Outages without notice; residents compare floors; one outage hit only the left-side 2-room apartments; a cold-water riser burst was learned by phone; some radiators cold while others warm in the same flat. Faults are **riser/column-specific** and invisible. |
| Parking | ~60 spaces for 180 apts; apartments with 2 permits; guest permits used as permanent; company cars on guest permits; plate change = return the old permit; lists compiled by hand. Strong vigilante tendencies (photos of people, threats). |
| Information flow | BIS registration / vote instructions reposted for weeks; new members cannot see history; half-filled Google Sheet. LV + RU mixed. |
| Decisions | RNP contract vote in BIS; **water metering decision due 2027-01-01** (MK noteikumi Nr. 524 amendments); water-loss difference ≈ 4 €/month per apartment. |
| Security | Door codes written on walls; strangers sleeping on the 9th floor; camera footage posted in chat; residents asked **not** to put a QR code on the door (use mailboxes). |
| Manager requests | Door lock, lights, lift — "more requests = faster fix", nobody tracks who already reported. |
| Land | Two cadastral parcels; functional-plot topic; land charges on bills. |

### 2.1 Parking law — corrected state (researcher, 2026-10-17)
The earlier assumption "since 2026-04-01 the manager must give police a plate list" is **outdated**:
1. From 31.03.2026 an amendment to MK Nr. 279 p. 285.27 (sign 849) removed the visible-permit wording;
   Riga municipal police then acted only if the building had submitted a plate list (Apr–May 2026).
2. 14.05.2026 Saeima passed the new Ceļu satiksmes likums Art. 19(6): a clearly visible permit must be
   behind the windscreen (in force ~early June 2026 — verify exact date on likumi.lv).
3. RNP update 02.06.2026: regular **and guest** permits work again; police do not need a plate list;
   they check only after a resident's call. Typical flat = 1 plate-bound main permit + 1 guest permit
   (not plate-bound).

**Consequence:** the parking module's core is the **permit registry + yes/no lookup**; the police
plate-list export is an **optional, per-building enforcement mode**, not the core flow.

## 3. Tech stack (CONFIRMED by owner 2026-10-17)

Laravel monolith — an intentional, documented deviation from the global TypeScript baseline
(ADR `docs/ADR/0001-laravel-monolith.md`). Laravel lives at **repo root** (deviation from the global
`backend/` + `frontend/` layout, recorded in ADR 0001; `frontend/mobile/` stays reserved and empty).

| Layer | Choice (pinned 2026-10-17, Packagist/Docker Hub) |
|---|---|
| Runtime | **PHP 8.4** (Pest 5 requires ^8.4; `config.platform.php = 8.4.21`) |
| Framework | **laravel/framework ^13.37** (Laravel 13, security fixes to Q1 2028) |
| Resident UI | **livewire/livewire ^4.4.7** + Alpine (bundled) + **Tailwind CSS ^4.1** (`@tailwindcss/vite`), Vite, Node 24 LTS |
| Admin | **filament/filament ^5.10.2** (Filament 5 = Filament 4 API on Livewire 4; needs ext-intl) |
| DB | **PostgreSQL 18.6** (`postgres:18.6-alpine`): JSONB, partial + exclusion indexes, composite FKs |
| Cache/queue/session | **Redis 8** (`redis:8-alpine` or Coolify Redis/Valkey), **laravel/horizon ^5.50** |
| PDF | **barryvdh/laravel-dompdf ^3.1** + embedded DejaVu Sans (Latvian diacritics + Cyrillic) |
| QR | bacon/bacon-qr-code (pure PHP; verify current major at install) |
| Images | intervention/image with GD (re-encode, EXIF strip; verify major at install) |
| Translatable catalogue labels | spatie/laravel-translatable ^6.14 |
| Errors | sentry/sentry-laravel ^4.29 → self-hosted GlitchTip (EU), `send_default_pii=false` |
| Tests/quality | pestphp/pest ^5.3, pest-plugin-laravel ^5.0, pest-plugin-browser ^5.1, larastan ^3.13 (level 6), laravel/pint ^1.32 |
| PWA | vite-plugin-pwa (Workbox) — NetworkOnly for HTML and `/livewire/*` |
| Fonts/icons | Inter Variable self-hosted (`@fontsource-variable/inter`), Lucide via blade-lucide-icons |
| Container | `serversideup/php:8.4-fpm-nginx-trixie-v5.0.1` (non-root), multi-stage Dockerfile |

**Deliberately NOT used** (lead decision, keeps the solo-maintainer surface small):
stancl/tenancy, spatie/laravel-multitenancy (wrong tenancy model), spatie/laravel-permission
(own `building_roles` table + `users.platform_role`), spatie/laravel-activitylog (own append-only
`audit_entries`), spatie/laravel-ciphersweet (own `PlateVault` with per-building HKDF index keys —
CipherSweet indexes are per-field, not per-tenant), spatie/laravel-medialibrary (one private disk +
re-encode is enough), Browsershot/Chromium, Reverb/websockets (polling), SQLite in tests, Flux Pro,
Google Fonts/Maps, SMS, native apps, paid analytics.

## 4. Architecture

### 4.1 Hosting & URLs
- Coolify (EU server), one image → a Docker Compose resource with four services: `migrate` (one-shot,
  `php artisan migrate --database=pgsql_owner --force --isolated`, then exits), `web`, `worker` (Horizon),
  `scheduler` (`schedule:work`). `web`/`worker`/`scheduler` start only after `migrate` completed
  successfully. Postgres and Redis as separate Coolify resources, scheduled encrypted backups to EU
  S3-compatible storage.
- **Three DB roles** (least privilege, MVP-SPEC A.2a): `majas_owner` (owns schema, used **only** by
  `migrate`), `majas_app` (DML only, not owner — cannot ALTER/DISABLE TRIGGER/TRUNCATE; no UPDATE/DELETE on
  append-only tables) used by `web` + `worker`, `majas_retention` (DELETE on retention-managed tables only,
  trigger-checked age) used **only** by prune commands running in `scheduler`. Each service receives only
  its own credentials.
- **Deploy gate:** CI (GitHub Actions) runs tests then calls the Coolify deploy webhook — still
  "auto-deploy from main", but a red main never ships (ADR 0002).
- **Working host:** `kaimini.coredigify.com` (path-based tenancy `/b/{building-slug}/…`). No
  per-building subdomains. Because coredigify.com hosts client WordPress sites, the session cookie is
  `__Host-mp_session`, `SESSION_DOMAIN` unset, Secure, SameSite=Lax (anti cookie-tossing). The remember
  cookie is renamed to `__Host-mp_remember` (custom guard recaller name) and the `XSRF-TOKEN` cookie is
  disabled (Livewire uses the meta token), so sibling `*.coredigify.com` sites cannot toss a login-CSRF
  cookie. This residual shared-parent risk is one more reason to move to the own domain before printing.
- **Own domain BEFORE printing invite letters** (printed URLs cannot be changed): shortlist in
  `BUSINESS.md §6`; old host 301-redirects forever.
- `/up` returns JSON `{status, version}`; `X-App-Version` header; version in footer (global rule).
- **No secrets in URLs or logs:** the invite QR encodes `/join#c=CODE` and the magic link is
  `/login/confirm#t=TOKEN` — URL fragments never reach the server, nginx, GlitchTip or a Referer. A legacy
  `?c=` / path token is answered with an immediate 303 to the bare route (value discarded, never echoed).
  nginx uses a custom log format without query strings and with truncated IPs (IPv4 /24, IPv6 /48);
  `Referrer-Policy: no-referrer` globally; the Sentry `before_send` hook strips query, fragment and token path
  segments from request URLs and breadcrumbs (MVP-SPEC A.6a).

### 4.2 Tenancy (row-level, fail-closed)
- Single database. Every tenant table has `building_id NOT NULL`, an index starting with
  `building_id`, and `UNIQUE(building_id, id)` so children use **composite FKs**
  `(building_id, parent_id) → parent(building_id, id)` — cross-building links are impossible in SQL.
- `CurrentBuilding` singleton, `BuildingScope` global scope, `BelongsToBuilding` trait (auto-fills
  `building_id`), `SetCurrentBuilding` middleware registered as **persistent** Livewire middleware and
  as Filament `tenantMiddleware(..., isPersistent: true)`.
- `Building` itself is **not** a tenant model (no `BuildingScope`): route-model binding `{building:slug}` and
  Filament tenant resolution can load it without a context. What a user may do with it is decided by policies.
- **Fail closed:** querying a tenant model with no current building throws `MissingBuildingContext`.
  Jobs implement `TenantAware` (building_id serialized + restored by the `RestoreBuildingContext` job middleware).
- **Context is set once and cannot be re-pointed.** `CurrentBuilding::set($b)` throws
  `BuildingContextAlreadySet` if a *different* building is already set; the only way to visit several buildings
  is `CurrentBuilding::runFor($b, fn)` which sets, runs and restores the previous context (also on exception).
  **`set()` / `runFor()` callers are allow-listed** (Arch test; any other caller in `app/` fails CI):
  1. `App\Http\Middleware\SetCurrentBuilding` — resident routes (from the bound `{building:slug}`) and the Filament
     `manage` panel (from `Filament::getTenant()`, which Filament resolved via `User::getTenants`/`canAccessTenant`).
  2. `App\Tenancy\InviteResolver` — `/join` (see below).
  3. `App\Tenancy\UserScopedQuery::eachBuilding()` — via `runFor`.
  4. `App\Tenancy\BuildingIterator` — via `runFor`.
  5. `App\Tenancy\Jobs\RestoreBuildingContext` — job middleware for `TenantAware` jobs.
  6. `App\Platform\BreakGlass` — via `runFor` inside a `READ ONLY` transaction.
  7. `App\Platform\PlatformBuildingContext` — the **named platform-admin context** for the `platform` panel
     (`BuildingGeneratorPage`, `BuildingResource` structure actions) and console seeders:
     `PlatformBuildingContext::run(Building $b, PlatformPurpose $purpose, callable)` requires
     `platform_admin` + MFA in HTTP (console: `app()->runningInConsole()` only for `seed`), writes a platform-chain
     audit entry (purpose, building), and uses `runFor`. Purposes: `generate`, `structure_edit`, `seed`.
- **Resident route order:** `auth → SetCurrentBuilding → member` (group for every `/b/{building:slug}/…`). `member`
  calls `membershipFor($building)` with the context already set and returns **404** (not 403, so building slugs
  cannot be enumerated) for non-members; role middleware (`role:section_elder`, step-up) comes after `member`.
- **Filament native tenancy:** Filament calls `User::getTenants(Panel)` and `User::canAccessTenant($building)`
  **before** `tenantMiddleware` runs, so both read only through `UserScopedQuery::for($this)->roles()` /
  `->memberships()` (DTOs, no tenant context needed) and never touch a tenant model directly.
- **Sanctioned cross-building paths (the ONLY places the scope may be lifted)** — enforced by
  `Arch/TenancyArchTest`; any other `withoutBuildingScope()` / `withoutGlobalScope(BuildingScope::class)`
  / `CurrentBuilding::forget()` call fails CI:
  1. `App\Tenancy\InviteResolver` — `/join`: normalizes the code, computes `code_hmac`, looks up
     `apartment_invites` by the global `UNIQUE(code_hmac)` (valid, not revoked, not expired, uses < max),
     then **sets `CurrentBuilding`** to the invite's building and returns a `ResolvedInvite` DTO. Unknown /
     revoked / expired codes return `null` (identical response, throttled before the lookup).
  2. `App\Tenancy\UserScopedQuery` — built only via `UserScopedQuery::for(User)` from the authenticated
     user (Livewire/controllers pass `auth()->user()`; jobs pass the user id captured when the user
     requested the job). Every method filters by that user's id **inside** the class and returns DTOs /
     collections, never a Builder the caller could widen: `buildingIds()`, `memberships()`, `roles()`
     (BuildingSwitcher, post-login redirect, PIN limiter building lookup), `auditAboutMe()`
     (`subject_user_ids @> {me}` → Me\WhoViewed), and `eachBuilding(callable)` which **sets
     `CurrentBuilding` per building** of that user and runs the callback under the normal scope (Me\Privacy,
     GdprExporter, AccountEraser). Callers: `Shell\BuildingSwitcher`, `Me\Privacy`, `Me\WhoViewed`,
     `GdprExporter`, `AccountEraser`, `PinAuthService`, `Auth\PostLoginRedirect`, and `App\Models\User`
     (**only** in `getTenants()`, `canAccessTenant()`, `getDefaultTenant()` for Filament) — nothing else.
  3. `App\Tenancy\BuildingIterator` — scheduler/platform jobs that must visit every building (retention,
     digests): iterates building ids and sets `CurrentBuilding` for each; never queries tenant rows itself.
  4. `App\Platform\BreakGlass` — platform_admin cross-tenant read in the `platform` panel, reason required,
     audit-logged.
  Tests (`Feature/Tenancy/UserScopedQueryTest`, `Feature/Tenancy/InviteResolverTest`,
  `Feature/Tenancy/FilamentTenancyTest`, `Feature/Tenancy/ContextRepointTest`) prove a user can never receive
  another user's memberships, roles, audit entries or export rows, even when both are members of the same
  apartment/building; that an invite of building A never exposes building B rows; that Filament tenant
  resolution never throws `MissingBuildingContext` and lists/accepts only buildings where the user holds a
  manage role; that a non-member gets 404 on every `/b/{slug}` route; and that `set()` to a second building
  inside a request throws.
- Users are global (one person can be a member in several buildings). Series catalogue is platform data.
- Optional `organizations` above buildings (biedrība / kopība / manager company) for billing and portfolios.
- Postgres RLS on the most sensitive tables = Phase 3 defence in depth.

### 4.3 Building Series Catalogue (platform data)
```
building_series           (code "602", name{lv,ru}, years_from/to, floors_typical[], wall_material, sources[])
 ├ series_section_layouts (code "1-2-3-3", apartments_per_floor, positions[{position, side, type_code}])
 ├ series_apartment_types (code, rooms_count, area_m2_typical, label{lv,ru})
 │  └ series_rooms        (code kitchen|bath|wc|room1.., label{lv,ru}, radiator_count, towel_dryer)
 └ series_risers          (code_pattern, kind heating|cold_water|hot_water|sewage,
                           serves[{position, room_code|null}], confidence, source)
```
- **602 template = hypothesis.** Room-level heating riser topology is UNCONFIRMED in public sources
  (likely vertical single-pipe per room). Every riser carries `confidence`
  (`hypothesis | resident_confirmed | manager_confirmed | drawing_confirmed`) and `source_ref`.
  Water risers are grouped by **position column / side** (chat evidence).
- 602 section-layout presets: 2-2-3-3, 1-2-3-4, 1-1-2-2, 1-2-2-3 (+ RU variants 2-3-3-3, 2-3-3-4);
  the admin picks the preset per stairwell (Pūces 51 mix to verify: 1-room ~36 m², 2-room ~49–52 m²,
  3-room 61–64 m²).
- **Building generator** (series → sections → floors → first apartment no. → numbering direction →
  layout preset → preview grid → generate) creates sections, apartments, rooms, risers, riser links.
  Per-building overrides (merged apartments, renovations) never touch the template. Grid cells live in
  `apartment_cells` (one row per section/floor/position → exactly one apartment), so a merged apartment owns
  several cells and its riser links (MVP-SPEC A.4).
- Phase 3: `series_corrections` (building proposes → platform admin merges) and "riser discovery by
  co-occurrence" (2+ apartments in the same column reporting together → proposed shared riser).

### 4.4 Core domain (Package A)
```
organizations ─ buildings (slug, address, address_code, cadastre, series_id, settings JSONB)
  ├ land_parcels ─ section_land_parcel
  ├ sections (number, first_apartment_no, apartments_per_floor, floors, layout_code)
  │  └ apartments (number, floor, position, side, rooms_count, area_m2)
  │     └ rooms (code) ─ riser_links (riser ↔ apartment/room)
  ├ risers (code, kind, position, side, confidence)
  ├ building_privacy_notices (controller snapshot, covers_modules, published_at) → gate L-core / L2
  ├ apartment_invites (code_hmac, max_uses, expires_at, revoked_at)
  ├ apartment_memberships (user, role owner|tenant|family|contact, status pending|approved|ended)
  ├ building_roles (building_admin | section_elder | parking_officer | volunteer | manager_viewer*)
  └ audit_entries (append-only, hash-chained, subject_user_id for "who viewed my data")
users (email nullable, login_handle + pin_hash nullable, locale, platform_role) ─ login_tokens
```
\* `manager_viewer` is reserved in the enum; the manager portal ships in Phase 2.

Role powers are **split** so no single neighbour sees everything:
- `section_elder` (per stairwell): approves memberships, assisted onboarding, proxy reports, outage
  notices for the stairwell. **Cannot see plates.**
- `parking_officer` (usually the existing permit issuer): manages permits, may reveal plate → apartment
  with a typed reason; every reveal visible to the data subject.
- `building_admin`: settings, invites, exports (police export needs a **second approver**).
- Roles expire after 12 months unless re-confirmed; mandate is delegated by the house (removable).

### 4.5 Auth (resolves "magic link vs 72-year-old without e-mail")
1. **Invite code** from the mailbox letter (10 chars, unambiguous alphabet without 0/O/1/I/L, `XXXXX-XXXXX`, Cyrillic
   look-alikes mapped, stored as HMAC only) → choose role → **pending** membership → elder approves
   (existing members of that apartment are notified). Read-only heating/outage view while pending.
2. Login method, user's choice:
   - **E-mail**: magic link (GET opens a confirm page, login on POST — mail scanners cannot burn it)
     **plus a 6-digit code** in the same mail (iOS PWA has a separate cookie jar).
   - **No e-mail**: personal handle (apartment number + 4 chars from the unambiguous alphabet, e.g.
     `145-K7MQ`, ≈923k per apartment prefix, printed on a "Mans PIN" card) + **6-digit PIN** (Argon2id).
     **Blocking limiters (all must pass):** per handle 5 wrong → 15-min cooldown, 10/day → lock until elder
     reset; **per IP** 20/h (IPv6 by /64); **per building** 100/h (building resolved from the handle's
     memberships; elders + building_admin alerted at 50% and on hit; PIN login for that building pauses 15 min,
     e-mail login unaffected). **Global monitor is alert-only** (never blocks — a blocking global cap would let
     ~50 rotated IPv6 /64 prefixes lock every e-mail-less senior out of the platform): failed PIN attempts
     > 1000/h platform-wide, or unknown-handle attempts > 300/h, alert the platform admin, who can manually
     pause PIN login per building. Unknown handles count against the IP limiter and the global monitor only.
     **Identical response and timing** for unknown handle, wrong PIN and locked handle (dummy Argon2id
     verify for unknown handles; the cooldown message is keyed to the device/IP, never to handle existence).
     **Weak PINs rejected at creation/reset** (deny-list: common PINs, same/sequential digits, repeated
     patterns, years 1930–2015 in first/last 4 digits, DDMMYY/YYMMDD dates, apartment number repeats).
     **PIN reset** only by the elder of the user's section (in person/phone): issues a one-time temporary
     code (8 chars, 24 h, hashed, single-use) that forces a new PIN on first use; audit-logged and the
     apartment's other approved members (and the user) are notified; cap 5 resets/day per elder.
3. "Remember this phone" on by default (long-lived remember token, revocable in "Mans dzīvoklis").
4. Elevated actions (approvals, reveals, exports, panels) require **MFA** (Filament built-in TOTP or
   e-mail OTP) and a step-up confirmation valid 2 h. Elevated roles therefore need an e-mail.
5. No CAPTCHA, no SMS (cost), no Smart-ID/e-signature anywhere.

### 4.6 Module 🔥 Heating & outages (Package B)
- Grid per stairwell (9 floors × 4 positions), vertical riser bands; resident view shows **riser/column
  aggregates only** + own apartment (per-apartment states would leak occupancy).
- **Reporter privacy rule (riser + floor = apartment):** a column has one apartment per floor, so any
  per-floor datum identifies an apartment. Residents receive **counts only** per riser/column — status,
  confirmed, `distinct_apartments`, `floors_covered` (a number), `status_counts`, coverage total, flags —
  never `floors_reported`, per-floor segments, `top_floor_only` or apartment ids (residents get a generic
  "augšējie stāvi → gaiss stāvadā" tip instead). Per-floor/per-apartment views: `building_admin` (all
  sections) and `section_elder` **only for their own section_id(s)**; an elder of stairwell 1 sees stairwell 5
  exactly like a resident.
- Report in ≤4 taps: room tiles from the template → cold / lukewarm / warm / overheated → confirm.
  Optional °C, triage hint (air / valve / whole flat), private note (admin-only).
- **Confirmation rule:** a riser is coloured as confirmed only with reports from **≥2 different
  apartments**; single report = "unconfirmed" (outline); show coverage ("dati no 4 no 9 stāviem").
  Technician/admin can mark "checked — normal / air vented / fixed". A "my radiator is not on this riser"
  dispute freezes colouring of that column until resolved.
- **Survey rounds** (comparable snapshots) + ad-hoc reports valid 7 days.
- **Outages** on the same grid as generic **incidents** with "Man arī" confirmations (unique per
  apartment): "Jau ziņojuši 6 dzīvokļi Jūsu stāvadā — tas nav tikai pie Jums." Elder/admin can post an
  outage notice (scope, reason, expected end). Manager emergency phone always visible; "not an emergency
  channel" notice. Residents never pick a riser: `OutageScopeResolver` maps apartment + kind to a scope
  (one riser of that kind → riser; several → ask the room "Virtuvē / Vannas istabā / Visur"; unknown links →
  column; else section) and joins the open incident with the same de-dup key (MVP-SPEC B.2a).
- **Manager package** ("pieteikums siltumsistēmas regulēšanai"): PDF + CSV with stable report number,
  always: per riser status, confirmed, distinct-apartment count, `floors_covered` count, coverage, timeline,
  triage counts incl. **"viss stāvads auksts"** (whole-riser-under count), confidence; column-level grid
  snapshot. **Per-floor lists and the per-floor grid snapshot only if `share_with_manager.heating_floors`;
  apartment numbers only if `share_with_manager.apartment_numbers` (which requires heating_floors).** No
  reporter identities, no private notes. Both flags default off, change = building_admin + step-up + audit.
  Elders generate reports only for their own section(s).
- WhatsApp-ready weekly digest text (share link, no API) for the elder to paste into the stairwell chat.

### 4.7 Module 🚗 Parking (Package C)
- Lots, **permits** (number, main|guest, issuer, validity, status, replacement history; guest permits are
  NOT plate-bound), **vehicles** (encrypted plate + per-building blind index, country, company-car flag).
- `PlateNormalizer`: uppercase, strip spaces/dashes/dots, Cyrillic look-alikes → Latin, `[A-Z0-9]{1,8}`.
- `PlateVault`: ciphertext with dedicated `PLATE_ENC_KEY` (never APP_KEY); index =
  HMAC-SHA256(HKDF(`PLATE_INDEX_KEY`, building_id), normalized) + key version. AEAD associated data binds
  building_id + table + row id + key version (no ciphertext swapping between rows/buildings). Rotation re-keys
  one building per transaction (ciphertext + bidx together). Keys escrowed offline.
- **Lookup** (verified residents): only "Reģistrēts šajā mājā" / "Nav atrasts reģistrā" (neutral grey,
  never red/"svešs"), plus list completeness ("sarakstā 64% dzīvokļu" = apartments with ≥1 active
  vehicle or a current "mums nav auto" declaration ÷ all apartments of the building, floored). Exact match only. Quota
  5/h + 20/day per user, 200/day per building, elder alert at 50%. Every lookup logged.
- **No side-channel lookups:** resident-declared vehicles always start `pending` (officer verifies → `active`),
  so declaring a plate already active in another apartment gets the same "Gaida apstiprinājumu" answer and is
  routed to the officer as a conflict; reporters never see whether a sighted plate matched the registry. Each
  declaration and each sighting writes a `plate_lookups` row (`reason_code` `declaration` / `sighting`) and
  consumes the same lookup quota.
- **Reveal** plate → apartment: `parking_officer`/`building_admin` only, reason enum + text, cap 10/day,
  audit-logged, visible in the subject's "Kas skatīja manus datus".
- **Sightings**: structured report (lot, time, optional in-app photo re-encoded/EXIF-stripped, deleted
  after 30 d; record after 12 months); visible to reporter, officer, admin only; "repeat" only with
  ≥2 independent reporting apartments in 30 days; owner can dispute ("I am a guest"). **Quotas:** 10 sightings
  per user per day (max 3 for the same plate), 100 sightings and 50 photos per building per day, 500 MB photo
  storage per building (MVP-SPEC C.5).
- **Vehicle removal** ("Dzēst manu numuru", AccountEraser, retention) goes through one `VehicleRemovalService`:
  in one transaction it first moves every active main permit bound to that vehicle to `revoked`
  (`status_reason=vehicle_removed`) and opens an officer task, then deletes the vehicle — the permit CHECK can
  never block a deletion.
- **Declaration campaign + amnesty window** with per-stairwell progress ring (aggregate only); seed from
  the permit issuer's existing list with the permit issuer's endorsement — **only after gate L1** (controller + DPA).
- **Analytics**: aggregate per stairwell for residents; per-apartment only for officer/admin and only after
  the house adopted parking rules (`parking_rules_adopted_at`).
- **Exports** (police list = optional enforcement mode B/C; manager list): admin request + second
  approver, signed URL 10 min, formula-injection-safe CSV, no apartment numbers unless required.
- **Two-level gate (enforced in policies + model guards, not only UI):**
  - **Level 1 — processing allowed:** L-core (§5) **and** the signed DPA's scope includes `parking`. Required for
    ANY write to `vehicles`, `permits` (incl. `vehicle_id`), `apartment_parking_declarations`, `sightings`,
    `plate_lookups` and for the permit import page. Before L1 only `parking_lots` and sign/banner drafts exist.
  - **Level 2 — resident-facing parking:** L1 + DPIA-lite completed + published building privacy notice that
    covers parking + `mandate_ref` (house decision / authorised person). Required to enable `modules.parking`
    (lookup, sightings, declarations, Manas caurlaides). Between L1 and L2 only parking_officer/building_admin
    prepare data in Filament.
  - Losing a level (e.g. DPA withdrawn) immediately blocks the writes/flows of that level; gate changes are audited.
- Parking-lot sign generator (Art. 14 notice, LV/RU, QR) and an editable "current Riga rule" banner.

### 4.8 i18n & accessibility
LV default + RU from day 1 (`lang/lv.json`, `lang/ru.json`, CI check for missing keys, native RU review).
Language picked on the first screen (two big buttons). WCAG 2.2 AA minimum, 48px targets, body 17px,
"Lielais teksts" (senior) mode, icon + word + pattern for every status (never colour alone).

## 5. Security & GDPR baseline (OWASP Top 10 2025)
- **A01** fail-closed tenancy + composite FKs + policies in every Livewire action (`#[Locked]` IDs,
  `authorize()` per action) + Filament queries scoped explicitly (selects, global search off for
  Vehicle/Sighting/Audit) + generated cross-tenant test suite blocking merge.
- **A02** strict nonce CSP (Livewire CSP-safe mode where available; any `unsafe-eval` tracked as debt),
  HSTS, frame-ancestors none, Permissions-Policy, APP_DEBUG=false, Horizon behind auth.
- **A03** pinned versions, `composer audit` + `npm audit` in CI, Dependabot.
- **A04** dedicated keys (APP_KEY, PLATE_ENC_KEY, PLATE_INDEX_KEY, INVITE_KEY), rotation via key version,
  offline escrow + restore drill.
- **A07** hashed single-use tokens, POST-confirm magic link, rate limits, no enumeration, MFA for roles.
- **A09** append-only, hash-chained `audit_entries` — **one chain per building** (+ one platform chain),
  computed in a BEFORE INSERT trigger serialised by `pg_advisory_xact_lock` per chain; trigger rejects
  UPDATE always and DELETE unless `current_user = majas_retention` and the row is past retention (an anchor
  row keeps the chain verifiable after pruning). `majas_app` is not the table owner and has no
  UPDATE/DELETE/TRUNCATE grant, so it cannot disable the trigger. `audit_chain_heads` and `audit_chain_anchors`
  are SELECT-only for `majas_app` (heads are written only by the SECURITY DEFINER insert trigger; anchors are
  INSERT-only for `majas_retention`) — a forged anchor could otherwise make the verifier skip history. The
  DELETE guard is a separate **SECURITY INVOKER** function (inside a DEFINER function `current_user` would be
  the owner and pruning would always fail). Anomaly alerts.
- **Account deletion never touches audit rows:** the `users` row becomes a tombstone (e-mail, handle, PIN,
  display name, MFA, tokens cleared; `anonymised_at` + `deleted_at` set); audit rows keep pointing at it and
  render "Dzēsts lietotājs". Audit `data` never holds plaintext PII, so nothing else needs scrubbing.
- **Controllership (split, explicit):**
  - **CoreDigify = controller** for **platform accounts**: `users` identity/credential columns (e-mail, login
    handle, PIN hash, MFA, remember tokens), `login_tokens`, session/security logs and IP HMACs, the platform audit
    chain. Purpose: running and securing the login service; basis Art. 6(1)(b) (service terms) and 6(1)(f)
    (security). Covered by the platform account notice (`privacy_notice_versions`, `covers_modules ∋ account`).
  - **The house = controller** (its authorised person, kopība or biedrība, recorded as `settings.controller`) for
    **house data**: apartment memberships (owner/tenant/family role), building roles, heating reports, incidents and
    confirmations, survey rounds, manager reports, the building audit chain, and all parking data.
    **CoreDigify = processor** for house data under an Art. 28 DPA whose scope lists the modules
    (`settings.dpa_scope ⊇ {core, heating, outages}` and later `parking`).
- **Core processing gate L-core (blocks wave 1):** `/join` redemption and invite-letter printing for a building
  are refused (neutral "Šī māja vēl nav atvērta pieteikšanai" / InviteBatchPage disabled with the missing
  conditions listed) until `settings.controller{name, contact}`, `dpa_signed_at` + `dpa_ref` with
  `dpa_scope ⊇ {core, heating, outages}`, and a **published building privacy notice** (`building_privacy_notices`,
  `covers_modules ⊇ {core, heating, outages}`) exist. Model guards refuse new memberships, heating reports and
  incident confirmations below L-core (seeders of structure are unaffected — structure is not personal data).
  The Art. 13 notice (building + platform account notice) is shown at `/join` before the code is redeemed and the
  accepted versions are stored. Losing L-core stops new redemptions, prints and heating/outage writes (read-only
  banner), alerts building_admin + platform admin, and is audited. Parking L1/L2 build on L-core (§4.7).
- **GDPR:** DPIA-lite before residents use parking (L2); Art. 30 record + lawful basis register in
  `BRAIN/docs`; privacy notices LV/RU; self-service export (ZIP) + deletion; "who viewed my data" page;
  users 18+; no names/phones collected in MVP; no free text visible to other residents. Alerts to elders /
  officers / admins about lookups, PINs or invites carry **counts only** — never a plate, bidx, handle or user.
- **House data portability:** building_admin can export a building-level structural/aggregate package
  (sections, apartments structure, risers + confidence, incident history, survey aggregates, manager report
  metadata + column-level aggregates — per-floor data and apartment numbers always stripped) with no personal
  data — for a change of manager or controller (MVP-SPEC A.8, ADMIN-004).
- **Never build:** door-code storage, camera/video uploads or streams, residents' directory, "complain about
  apartment X", public sightings feed, plate OCR, raw chat import or AI processing of the chat.
- **Retention:** sighting photos 30 d; sighting records 12 mo; plate lookups 6 mo; vehicle 30 d after permit
  end / membership end; login tokens 1 d; IPs HMACed after 90 d; heating ad-hoc reports end of season + 1 mo;
  audit log 24 mo; backups 30-day rotation, encrypted, EU. **Infrastructure logs:** nginx/Coolify access logs
  hold only truncated IPs and no query strings, kept **14 days** (Docker `json-file` rotation + Coolify log
  retention); Laravel logs daily, 14 days, no IPs/tokens; GlitchTip events 30 days.

## 6. Roadmap

Build order **A → B → C** (lead recommendation): the heating season has started (2026-10), and Package C
needs human/legal prerequisites (controller, DPA, permit issuer list, house rule) that run **in parallel**
while A and B are built. Packages are independent; the owner may swap B and C.
**Pending owner sign-off (explicit, required before Package B starts; Package A proceeds regardless):** the
original brief ranked parking 1st and heating 2nd. Rationale for A → B → C: heating season is live, parking
gate L1 needs a signed DPA before any plate is stored, and since the 2026-06 law change the police no longer
need a plate list. Recorded as 🎯 in `BRAIN/MANUAL-TODO.md` — if the owner keeps parking first, swap 1a/1b
(Package A is identical either way). Wave 1 additionally needs gate **L-core** (controller + DPA + notice, §5).

| Phase | Content | Target |
|---|---|---|
| **0 = Package A Foundation** | Skeleton, Docker/Coolify/CI, tenancy, auth (invite + e-mail + PIN), roles, LV/RU, design system + app shell, series catalogue + generator + 602 + Pūces 51 seed, Filament panels, audit, GDPR self-service, invite letter PDF, version visibility | ~3 weeks |
| **1a = Package B Heating** | Riser grid, room reports, survey rounds, outages + "Man arī", proxy entry, manager package PDF/CSV, WhatsApp digest. Pilot wave 1 (stairwell 5) opens here | ~2 weeks |
| **1b = Package C Parking** | Permits, vehicles, lookup, reveal, sightings, declaration campaign, analytics, exports, legal gate, sign generator | ~2–3 weeks |
| **2 Info hub + manager bridge** | 🎯 WATER-001 meter-decision helper (house deadline 2027-01-01 — pull forward if possible); manager portal (read / acknowledge / status / reply, per-data-type sharing); request tracker with "Man arī" counter for lift/lights/door lock + manager ref no.; decision register (kopības lēmumu reģistrs) with BIS links; welcome pack / "Jaunais kaimiņš"; BIS registration self-checklist (yes/no only); vote deadline reminders + participation by stairwell; announcements; Web Push + e-mail digests; printable paper form (anketa); cameras guidance page; floor-captain role | Nov–Dec 2026 |
| **3 Product** | Self-signup for new buildings (VZD address-code prefill), series corrections workflow, riser discovery by co-occurrence, public series preview/demo grid, seed 104 → 467 → 119 → 103, compliance pack per building (DPA, Art. 30, LIA, DPIA, notice), manual invoicing → billing, anonymous series benchmarks (k ≥ 5 buildings), Postgres RLS, own-domain migration if not done | Q1 2027 |
| **4 Extras** | Meeting organiser (agenda, proxy templates, shares/quorum calculator, protocol LV/RU — forms only, never binding), heat-node log (manager enters supply/return °C), planned-maintenance calendar, riser shut-off cards + key register (elder-only), emergency fridge card, land dossier, renovation cost helper (BIS 602 typical-project €/m²), document vault (AI summaries only with caps, later), contractor dispatch, Telegram bot, lost & found / tool library | 2027+ |

**Panel ideas grafted** (phase): riser-addressed outage notice (B), "Man arī" counter (B, extended 2),
manager package with report number (B), proxy/elder-assisted entry (A/B), PIN login (A), invite letter
LV/RU (A), language-first onboarding (A), senior mode (A), coverage display + ≥2-apartment rule (B),
riser dispute (B), WhatsApp digest share (B), amnesty + progress ring (C), split parking_officer role (C),
two-person export (C), legal-status banner (C), parking sign generator (C), "who viewed my data" (A/C),
water-loss helper (2), manager portal + acknowledge SLA (2), decision register (2), welcome pack (2),
series benchmarks (3), compliance pack (3), heat-node log (4).

**Pilot success metric & stop rule:** ≥25% of stairwell-5 apartments active within 60 days of wave 1,
≥3 of 9 floors covered per riser in stairwell 5 during the first survey round. If missed → re-plan
outreach before building Phase 2.

## 7. Business model summary (detail: `BRAIN/docs/BUSINESS.md`)
- **Residents pay nothing, ever** (core heating, outages, lookup, own permits). No ads.
- Pilot free. Paid layer is **per building**, bought by the association/kopība or the manager:
  hypothesis €0.20/apartment/month (min €15, cap €60/month per building) for registry exports, manager
  package, analytics, documents; manager portfolio tier later; €29–49 one-off "vote readiness" pack.
- Running cost target < €15/month total until paying buildings exist (shared Coolify server).
- GTM: pilot proof → same-series buildings in Purvciems/Pļavnieki/Imanta/Ziepniekkalns via elders and
  associations; RNP and big managers are a long cycle.

## 8. Open questions / human blockers
- Owner sign-off on build order A → B → C (brief said parking first) — needed before Package B starts.
- Data controller for the pilot (house-authorised person / association) + DPA (scope core, heating, outages,
  parking) + published building notice → blocks **gate L-core = wave 1** (any join / invite print); DPA scope
  `parking` → parking gate L1 (any plate storage, incl. the permit-list import); DPIA-lite + notice + mandate →
  gate L2.
- Production DB bootstrap (`database/sql/roles.sql` as superuser: three roles, `majas` DB owned by
  `majas_owner`, `public` schema owner, extensions) created once on the Coolify Postgres.
- Permit issuer cooperation (existing list as seed, endorsement) and house parking rule decision.
- BIS mājas lieta drawings (riser topology) → raises riser confidence; until then "hypothesis".
- Pūces 51 section layout per stairwell (room mix) and second land parcel number.
- Elders / floor captains for stairwells 1–5; building_admin for the pilot.
- Own domain choice (must be decided before invite letters are printed) — owner shortlist kopiba.lv /
  stavads.lv / kaiminu.lv (kopiba.lv taken, kaiminu.lv free per Openprovider 2026-10-17); architect proposal
  majaspaligs.lv, availability unchecked (`BUSINESS.md §6`).
- RNP contact for the heating report format (present the first report in person).
- Exact in-force date and wording of Ceļu satiksmes likums Art. 19(6) (likumi.lv).
- Native Russian reviewer for UI strings.

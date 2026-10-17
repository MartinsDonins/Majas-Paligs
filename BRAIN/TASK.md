# TASK — Mājas Palīgs

Format: `[ICON] TYPE-NR: Latvian title` — description in English.
Specs: `BRAIN/docs/MVP-SPEC.md` (packages A/B/C), `BRAIN/docs/DESIGN-SYSTEM.md`, `BRAIN/docs/BUSINESS.md`.

Custom types:
- **SERIES** — building series catalogue & generator.
- **HEAT** — heating map, riser reporting, outages.
- **PARK** — parking registry.
- **WATER** — water-loss / meter-decision tools (Phase 2).
- **MGR** — manager-facing portal and request tracking (Phase 2).
- **INFO** — information hub: welcome pack, decision register, announcements (Phase 2).
- **GOV** — governance & legal artefacts (controller, DPA, DPIA, notices).

Build order (lead recommendation): **A → B → C**; Package C legal prerequisites (GOV-*, human tasks) run in parallel.
Package B starts only after the owner's explicit sign-off on this order (MANUAL-TODO); wave 1 needs gate L-core (GOV-008).

## Fāze 0 — Pamats
- ✅ DOC-001: Projekta plāna izveide - COMPLETED — BRAIN/PLANNING.md from chat analysis.
- ✅ SEC-001: Personas datu izslēgšana no git - COMPLETED — `DOC/` and `*.zip` in .gitignore.
- ✅ DOC-002: Komandas paneļa sintēze un MVP specifikācija - COMPLETED — PLANNING.md rewrite, MVP-SPEC, DESIGN-SYSTEM, BUSINESS (2026-10-17).
- ✅ DOC-002.1: Kvalitātes vārtu (Karen) labojumi - COMPLETED — B1 sanctioned cross-building queries, B2 DB roles + immutable audit + tombstone deletion, B3 heating reporter privacy + section-scoped elders, B4 two-level parking gate, B5 PIN brute-force limits; suggestions folded into specs (2026-10-17).
- ✅ DOC-002.2: Kvalitātes vārtu (Karen) 2. kārtas labojumi - COMPLETED — B1 tenancy context allow-list (`CurrentBuilding::set/runFor` callers, Filament getTenants/canAccessTenant via UserScopedQuery, `auth → SetCurrentBuilding → member` 404), B2 core gate L-core + split controllership; 17 suggestions (PIN global monitor alert-only, secrets out of URLs/logs, audit-table grants + INVOKER delete guard, roles.sql DB bootstrap, VehicleRemovalService, registration oracles, sighting quotas, no offline queue, section_ids integrity, export stripping, ext-sodium check, log retention, WSL2 UID, sign-offs, AC renumbering) (2026-10-17).

## 📦 Pakete A — Pamats (Foundation)
- ✅ DEPLOY-001: Laravel projekta skelets — dev image (serversideup PHP 8.4 + intl/gd/exif/bcmath/pdo_pgsql/redis), create-project Laravel ^13.37 at repo root without overwriting BRAIN/DOC, Livewire ^4.4.7, Filament ^5.10.2, Tailwind ^4.1, `config.platform.php=8.4.21`, composer `require` ext-sodium/ext-intl/ext-pdo_pgsql/ext-gd/ext-redis, image build check for sodium XChaCha20 + Argon2id, `.gitattributes`. MVP-SPEC A.1. - COMPLETED (2026-10-18)
  - ✅ DEPLOY-001.1: Docker izstrādes vide — compose: app (Xdebug off by default, `USER_ID`/`GROUP_ID` build args remapping www-data for WSL2 bind mounts), vite, pgsql (bootstrap via the same `database/sql/roles.sql` for majas/majas_test), redis, mailpit, horizon, scheduler; named volumes; `scripts/dev.sh init` (composer install + npm ci into empty volumes); Windows: repo on WSL2 ext4 or `VITE_POLLING=1`. MVP-SPEC A.2. - COMPLETED (2026-10-18)
  - ✅ DEPLOY-001.2: Produkcijas Dockerfile un compose — multi-stage base/dev/assets/vendor/prod, non-root, HEALTHCHECK /up; Coolify compose with one-shot `migrate` (majas_owner) → web/worker/scheduler (majas_app, retention creds only in scheduler); nginx `mp_noargs` log format (no query strings, truncated IPs), json-file log rotation, 14-day log retention; verify serversideup v5 AUTORUN_* names and nginx include path. - COMPLETED (2026-10-18)
  - 🔍 DEPLOY-001.3: CI un Coolify izvietošana — GitHub Actions per MVP-SPEC A.3 (roles created in CI, migrations as owner, privilege tests as app/retention), deploy webhook on green main, VERSION check, lang parity, personal-data grep guard. - FOR REVIEW
  - ✅ DB-002: Datubāzes lomas un tiesības — majas_owner / majas_app / majas_retention; idempotent `database/sql/roles.sql` (DB `majas` OWNER majas_owner, `ALTER SCHEMA public OWNER`, CONNECT grants, extensions as superuser) used by dev, CI and prod; default privileges + REVOKE on append-only tables; `audit_chain_heads`/`audit_chain_anchors` SELECT-only for app, anchors INSERT-only for retention; INVOKER delete guard; connections pgsql / pgsql_owner / pgsql_retention; RolePrivilegesTest + GrantsCoverageTest + BootstrapTest. MVP-SPEC A.2a. - COMPLETED (2026-10-18)
  - ✅ DEPLOY-001.4: Versijas redzamība — VERSION → config('app.version'), footer, `/up` JSON, `X-App-Version` header. - COMPLETED (2026-10-18)
- ✅ DOC-003: Arhitektūras lēmumu ieraksti (ADR) — ADR 0001 Laravel monolith + repo-root layout, 0002 CI-gated deploy, 0003 row-level tenancy, 0004 plate vault, 0005 auth (invite + e-mail + PIN). - COMPLETED (2026-10-18)
- ✅ DB-001: Mājas, kāpņu telpas, dzīvokļi, lomas — organizations, buildings (settings DTO), land_parcels, sections, apartments, apartment_cells (merged apartments), rooms, risers, riser_links, building_counters; composite FKs; MVP-SPEC A.4. - COMPLETED (2026-10-18)
  - ✅ DB-001.1: Fail-closed multi-tenancy — CurrentBuilding (`set()` throws on re-point, `runFor()` set/restore), BuildingScope (throws MissingBuildingContext), BelongsToBuilding (`Building` itself not scoped), SetCurrentBuilding (persistent Livewire + Filament tenant middleware), resident group order `auth → SetCurrentBuilding → member` (404 for non-members), `User::getTenants/canAccessTenant/getDefaultTenant` via UserScopedQuery, TenantAware jobs + RestoreBuildingContext; sanctioned scope lifts InviteResolver, UserScopedQuery, BuildingIterator, BreakGlass; allow-listed `set()` callers incl. PlatformBuildingContext for the platform generator/seeders (PLANNING §4.2). - COMPLETED (2026-10-18)
  - ✅ DB-001.2: Starpīrnieku izolācijas testi — generated CrossTenantTest dataset (non-member 404, no tenant query before 404), MiddlewareOrderTest, FilamentTenancyTest, ContextRepointTest, CurrentBuildingTest, CompositeForeignKeyTest, QueuedJobContextTest, UserScopedQueryTest, InviteResolverTest, Arch/Larastan allow-lists for scope lifts **and** `set()/runFor()` callers. - COMPLETED (2026-10-18)
- ✅ SERIES-001: Sēriju katalogs — building_series, section layouts, apartment types, rooms, risers (JSONB, translatable labels, confidence + sources). - COMPLETED (2026-10-18)
- ✅ SERIES-002: Mājas ģenerators no sērijas — BuildingGenerator preview + generate (numbering formula, direction, layout preset per stairwell, overrides); Filament wizard with resident grid preview. - COMPLETED (2026-10-18)
- ✅ SERIES-003: 602. sērijas sagatave un Pūces iela 51 — Series602Seeder (layout presets, types, rooms, risers = hypothesis), PucesIela51Seeder (5×9×4 = 180, numbering 1..180, +4 rule, parcel 0100 071 0114 unverified + placeholder). Structure only, zero personal data. - COMPLETED (2026-10-18)
- ✅ AUTH-001: Pieslēgšanās ar e-pasta saiti un kodu — magic link `/login/confirm#t=…` (fragment; GET confirm page, POST login; legacy path/query token → 303 discard), 6-digit code in same mail, hashed single-use 15-min tokens, enumeration-safe, throttles, `__Host-mp_session`. - COMPLETED (2026-10-18)
- ✅ AUTH-002: Dzīvokļa uzaicinājuma kodi — 10-char unambiguous codes (HMAC only), Cyrillic-tolerant input, resolved only via InviteResolver (CoreGate check → `HouseClosed`), QR `/join#c=…` fragment read by Alpine (`?c=` → 303 discard), Art. 13 notice before role choice, max_uses 3, 60-day expiry, pending membership, elder approval, notify existing members. - COMPLETED (2026-10-18)
  - ✅ AUTH-002.1: Uzaicinājuma vēstules PDF — A5 LV/RU letter per apartment, QR to `/join#c=…`, controller + notice line, "Mans PIN" card, DejaVu Sans, 10-min signed URL, audit-logged; print only with L-core open. - COMPLETED (2026-10-18)
- ✅ AUTH-003: Ieeja bez e-pasta ar PIN — handle (apt + 4 chars) + 6-digit PIN (Argon2id); blocking limiters per handle / IP 20/h / building 100/h (elder alert); global 1000/h = **alert-only monitor** (+ manual per-building pause in platform panel); count-only alert payloads; identical response + timing for unknown handles; WeakPinRule deny-list; elder reset via single-use 24 h temp code forcing new PIN, audited, members notified; "remember this phone" (`__Host-mp_remember`), device list in "Mans dzīvoklis". MVP-SPEC A.6. - COMPLETED (2026-10-18)
- ✅ AUTH-004: Lomas un paaugstinātās darbības — building_roles (building_admin, section_elder, parking_officer, volunteer, manager_viewer reserved), 12-month expiry, policies, Filament MFA, step-up confirmation (2 h). - COMPLETED (2026-10-18)
- ✅ UI-001: LV/RU valodas — lang/lv.json + ru.json, SetLocale (user/cookie), first-screen language choice, Carbon locales, Filament vendor translations check, native RU review. - COMPLETED (2026-10-18)
- ✅ UI-002: Dizaina sistēma un lietotnes karkass — tokens.css (light/dark), Inter self-hosted, Lucide, Blade UI components, app shell (top bar, bottom nav, offline banner), senior mode, PWA manifest + service worker (NetworkOnly HTML). DESIGN-SYSTEM §2–5. - COMPLETED (2026-10-18)
- ✅ UI-003: Pievienošanās plūsma — language → code → apartment + role → access method → pending state; "Pievienot ikonu ekrānam" guide. - COMPLETED (2026-10-18)
- ✅ UI-004: Sākuma ekrāns un "Mans dzīvoklis" — Home skeleton with module cards, "Mana māja" card, deadline card; Mans dzīvoklis (members, devices, language, text size, theme). - COMPLETED (2026-10-18)
- ✅ UI-005: Vecākā panelis — approval queue, assisted onboarding ("Tas esmu es"), PIN reset (resident app, not Filament). - COMPLETED (2026-10-18)
- ✅ ADMIN-001: Filament paneļi — `platform` (series, buildings, generator inside PlatformBuildingContext, legal notices, privacy notice templates + platform account notice, PIN pause page, cross-tenant audit with break-glass) and `manage/{building}` (sections, apartments incl. merge override, risers, memberships, roles, invite batches gated by L-core, settings with gate status, building privacy notice page, audit); shared theme; global search off on sensitive resources. - COMPLETED (2026-10-18)
- ✅ ADMIN-004: Mājas datu eksports — BuildingExportPage: structure + aggregates ZIP (no personal data; manager reports as metadata + column-level aggregates only, stored snapshots never copied, per-floor/apartment numbers always stripped) for a change of manager/controller; step-up, audit, signed 10 min. MVP-SPEC A.8. - COMPLETED (2026-10-18)
- ✅ GOV-008: Pamata apstrādes vārti (L-core) — CoreGate: controller name + contact, DPA signed + ref + `dpa_scope ⊇ {core, heating, outages}`, published `building_privacy_notices` covering core/heating/outages; blocks `/join` redemption (HouseClosed), invite printing, model guards on memberships / heating reports / incident confirmations; regression closes + alerts + audits; `Feature/Gdpr/CoreGateTest`. PLANNING §5, MVP-SPEC A.5. **Blocks wave 1.** - COMPLETED (2026-10-18)
- ✅ SEC-002: GDPR pamati — split controllership (CoreDigify = controller of platform accounts, house = controller of house data), platform account notice + building notices LV/RU (covers_modules), notice id stored on membership, 18+ confirmation, self-service export across buildings (UserScopedQuery), account deletion = users tombstone (audit rows never touched), "Kas skatīja manus datus" page. - COMPLETED (2026-10-18)
- ✅ SEC-003: Audita žurnāls — append-only audit_entries, one hash chain per building + platform chain computed in BEFORE INSERT trigger (SECURITY DEFINER, fixed search_path) with advisory lock, chain heads (app SELECT-only) + anchors (retention INSERT-only), UPDATE always rejected, DELETE only majas_retention past 24 mo via SECURITY INVOKER guard, AuditChainVerifier rejects forged anchors. - COMPLETED (2026-10-18)
- ✅ SEC-004: Drošības galvenes un CSP — nonce CSP (Livewire CSP-safe where possible; any unsafe-eval tracked), HSTS, Permissions-Policy, `Referrer-Policy: no-referrer`, Livewire update throttle, `__Host-mp_remember` recaller name, XSRF-TOKEN cookie disabled, Sentry/GlitchTip `before_send`/`before_breadcrumb` scrubbing of query/fragment/token paths (send_default_pii=false), log redaction; SecretUrlLeakTest + AlertPayloadTest. MVP-SPEC A.6a. - COMPLETED (2026-10-18)
- ✅ SEC-005: Atslēgu pārvaldība — APP_KEY, PLATE_ENC_KEY, PLATE_INDEX_KEY, INVITE_KEY separation, offline escrow procedure, restore drill documented in docs/SECURITY.md. - COMPLETED (2026-10-18)
- 🔍 TEST-001: Pamata testu komplekts — all Package A tests from MVP-SPEC A.12, browser smoke via Mailpit. - FOR REVIEW
  - 🆕 TEST-001.1: Pārlūka dūmu tests — Browser/OnboardingSmokeTest with pest-plugin-browser + Playwright + Mailpit (HTTP/Livewire-level smoke `Feature/Onboarding/OnboardingSmokeTest` exists; real-browser run not set up yet).
  - 🆕 UI-002.1: Reālas ierīces pārbaude — Lighthouse mobile (a11y ≥ 95), 320px / 200% text, Livewire CSP-safe build with `wire:click` arguments on Android Chrome + iOS Safari, PDF letter print proof.
  - 🆕 SEC-004.1: Filament CSP parāds — panels still need `unsafe-inline` + `unsafe-eval` (Filament 5 inline scripts); revisit when Filament ships nonce/CSP support.
  - 🆕 DEPLOY-001.5: Pirmais CI palaidiens GitHub — push, verify all CI steps green, add `COOLIFY_WEBHOOK`/`COOLIFY_TOKEN` secrets, then first deploy.

## 📦 Pakete B — Siltums un atslēgumi (Fāze 1a)
- 🎯 HEAT-001: Siltuma karte (režģis pa stāviem) — building-grid component, riser bands, resident = ResidentRiserView counts only (no floors_reported / per-floor segments / top_floor_only) + own ring, per-apartment = admin + elder of own section only, list mode, stairwell tabs + mini map, `wire:poll.visible.60s`.
- 🎯 HEAT-002: Radiatoru ziņojumi — ≤4-tap report sheet (rooms from template, 4 states, "visās telpās vienādi"), optional °C, triage, private note, thank-you with riser count, undo; no offline queue in MVP (disabled "Saglabāt" + "mēģiniet vēlreiz" banner, selection kept in memory).
- 🆕 HEAT-003: Apsekojuma kārtas — survey_rounds (draft/open/closed, per section; `section_ids` validated by DTO + DB trigger, null = whole building admin-only), Home round card with progress, 7-day rolling window for ad-hoc reports.
- 🎯 HEAT-004: Atslēgumu ziņošana — incidents (scope_key de-dup, partial UNIQUE) + incident_confirmations, OutageScopeResolver (room question only with 2+ risers of a kind, column fallback, covering notice wins), "Vai ir ūdens?" flow, "Man arī", outage banner `wire:poll.30s`, emergency phone + "not an emergency channel". MVP-SPEC B.2a.
- 🆕 HEAT-005: Pārskats pārvaldniekam — manager_reports with gap-free number MP-YYYY-NNNN (CounterService), PDF + CSV (aggregates, coverage, triage incl. "viss stāvads auksts", confidence; floors only with `heating_floors`, apartment numbers only with `apartment_numbers`; no notes), elder only own sections (`section_ids` NOT NULL, validated by DTO + DB trigger, SectionIdsIntegrityTest), snapshot = exported fields, signed downloads, sent/response log.
- 🆕 HEAT-006: Stāvadu agregācija — RiserAggregator rules (≥2 apartments confirm, majority/mixed, floors_covered, status_counts, whole_riser_under, staff-only top_floor_only), riser_status + column_status projections, Resident/Staff read models, RecomputeRiserStatus debounced job.
- 🆕 HEAT-007: Stāvada pārbaude un strīdi — riser_checks ("Pārbaudīts"), riser_disputes ("mans radiators nav šajā stāvadā" freezes column), confidence upgrade path.
- 🆕 HEAT-008: Vecākā ziņojumi un paziņojumi — proxy/phone reports, outage notice composer targeting riser/column/section in LV/RU.
- 🆕 HEAT-009: WhatsApp kopsavilkuma kartīte — weekly aggregate digest text + wa.me share link, no API, no personal data.
- 🆕 TEST-002: Siltuma moduļa testi — MVP-SPEC B.8.

## 📦 Pakete C — Stāvvieta (Fāze 1b)
- 🎯 GOV-001: Stāvvietas moduļa divu līmeņu vārti — ParkingGate L1 (L-core open + `dpa_scope ∋ parking` → any plate/permit/sighting/declaration/lookup write, import page) and L2 (L1 + DPIA + notice covering parking + mandate_ref → resident-facing module); enforced in policy `before()`, model guards and routes; auto-off on regression; admin UI listing missing conditions. MVP-SPEC C.0.
- 🎯 PARK-001: Caurlaižu un auto reģistrs — parking_lots, permits (main/guest, issuer, replacement history, EXCLUDE overlap, composite FK (building, apartment, vehicle)), vehicles (ULID ids; PlateVault: dedicated key, AEAD associated data building/table/row/version, per-building HKDF blind index, atomic per-building re-key, company-car flag).
  - 🆕 PARK-001.1: Numura normalizācija — PlateNormalizer (Cyrillic look-alikes, separators, `[A-Z0-9]{1,8}`, country).
  - 🆕 PARK-001.3: Auto dzēšanas plūsma — VehicleRemovalService (only vehicle delete path: MyPermits, AccountEraser, prune:vehicles, officer): one transaction revokes bound active main permits (`status_reason`), opens `parking_officer_tasks`, then deletes; VehicleRemovalTest. MVP-SPEC C.5.
  - ⏸️ PARK-001.2: Esošā caurlaižu saraksta imports — PermitImportPage for the permit issuer's list (CSV), with the permit issuer's endorsement; seed source flagged. **Blocked until gate L1** (controller named + DPA signed). - ON HOLD
- 🎯 PARK-002: Auto numura pārbaude — yes/no only + completeness % (MVP-SPEC C.5 formula), exact match, reason chips, quotas 5/h + 20/day per user + 200/day per building (declarations and sightings consume the same quota and write plate_lookups rows), count-only anomaly alerts, plate_lookups log; resident declarations always `pending` (conflict routed to officer, identical response) — RegistrationOracleTest.
  - 🆕 PARK-002.1: Dzīvokļa atklāšana ar iemeslu — parking_officer/building_admin reveal with step-up, reason enum, 10/day cap, audit with subject users, e-mail notice, visible in "Kas skatīja manus datus".
- 🆕 PARK-003: Svešu auto ziņojumi — sightings (lot, time, plate, optional photo: client-side JPEG conversion, server jpeg/png/webp only (no HEIC), finfo MIME, size/MP limits, re-encode 1600px, EXIF strip, private disk, 5-min signed URL), quotas 10/day per user (3 per plate), 100 sightings + 50 photos/day + 500 MB per building, reporter never sees `matched_registered`, repeat flag ≥2 reporter apartments/30 d, dispute "Esmu viesis".
- 🆕 PARK-004: Stāvvietas analītika — stat tiles (permits per apartment, 2+ main permits, plates seen without registration), residents per-stairwell aggregates only, per-apartment only after `parking_rules_adopted_at`.
- 🆕 PARK-005: Saraksts policijai / pārvaldniekam — optional per enforcement mode, four-eyes approval, signed 10-min URL, formula-safe CSV + PDF, not stored.
- 🆕 PARK-006: Manas caurlaides un deklarēšana — MyPermits, ChangeVehicleWizard (return old permit), DeclarationWizard ("mums nav auto"), amnesty window, per-stairwell progress rings, "dzēst manu numuru".
- 🆕 PARK-007: Juridiskā statusa baneris un stāvvietas zīme — editable legal_notices banner (current Riga rule), Art. 14 parking-lot sign PDF LV/RU with QR.
- 🆕 PARK-008: Stāvvietas datu glabāšanas termiņi — `prune:*` commands in scheduler on `pgsql_retention`: photos 30 d, sightings 12 mo, lookups 6 mo, vehicles 30 d after permit/membership end, annual re-confirmation, Livewire tmp purge hourly.
- 🆕 TEST-003: Stāvvietas moduļa testi — MVP-SPEC C.8.

## 🧑‍⚖️ Pārvaldība (paralēli A/B; GOV-002 + GOV-005 bloķē vilni 1 caur L-core, pārējie bloķē C)
- 🎯 GOV-002: Apstrādes pārziņa noteikšana un DPA — template DPA (CoreDigify = processor for house data, scope list core/heating/outages/parking), controller = house-authorised person, kopība or association; CoreDigify's own controller role for platform accounts documented; owner signs off. **Unblocks gate L-core (wave 1)** and, with scope `parking`, parking gate L1 (no plate stored before this).
- 🆕 GOV-003: DPIA-lite stāvvietas modulim — unblocks gate L2; EDPB WP248 criteria, LIA for sightings, accepted residual risks (lookup enumeration).
- 🆕 GOV-004: Apstrādes reģistrs un tiesiskā pamata reģistrs — Art. 30 record in BRAIN/docs, lawful basis per data type.
- 🎯 GOV-005: Privātuma paziņojums LV/RU — two texts: platform account notice (CoreDigify as controller) and building notice template (house as controller, CoreDigify processor) — purposes, bases, retention incl. 14-day infra logs, sub-processors, DVI complaint right; native RU review. Building notice covering core/heating/outages **blocks wave 1** (L-core).
- 🆕 GOV-006: Mājas lēmuma projekts stāvvietas noteikumiem — neutral template text (one permit per apartment vs more, amnesty, data use) for the owners to decide; app only prepares.

## Fāze 2 — Informācijas centrs un pārvaldnieka tilts
- 🎯 WATER-001: Ūdens uzskaites lēmuma palīgs — monthly bill entry, loss per apartment, remote-read meter payback model, neutral vote options (per person vs per area); target before 2026-12-15 (house deadline 2027-01-01).
- 🆕 MGR-001: Pārvaldnieka portāls — manager_viewer role, read / acknowledge / status / reply, per-data-type sharing switches, e-mail/Telegram digest.
- 🆕 MGR-002: Pieteikumu skaitītājs — incidents kinds lift/lights/door_lock/other, "Man arī" + manager ref no., status New/Seen/Assigned/Done/Rejected.
- 🆕 INFO-001: Kopības lēmumu reģistrs — decisions with date, method, result, BIS/protocol link.
- 🆕 INFO-002: "Jaunais kaimiņš" un mājas ceļvedis — welcome pack (manager contact, emergency numbers, parking rules, BIS steps LV/RU, basement FAQ).
- 🆕 INFO-003: BIS reģistrācijas pašpārbaude — owner self-reported yes/no (BIS registered, e-mail present), per-stairwell aggregate, vote deadline reminders.
- 🆕 INFO-004: Paziņojumu dēlis — announcements by elder/admin, LV/RU, door-code pattern warning.
- 🆕 UI-006: Web Push un e-pasta kopsavilkumi — opt-in after first report; iOS only for installed PWA.
- 🆕 UI-007: Papīra anketa un ledusskapja kartīte — printable A4 form for elder entry, A5 cheat card.
- 🆕 DOC-004: Kameru vadlīnijas LV/RU — EDPB 3/2019 + DVI practice, template decision text; no camera features.
- 🆕 ADMIN-002: Stāva kapteiņa loma — floor-captain (per floor/column) volunteer role with limited powers.

## Fāze 3 — Produkts
- 🆕 SERIES-004: Sērijas labojumu plūsma — series_corrections propose → platform review → template diff.
- 🆕 SERIES-005: Stāvadu atklāšana pēc sakritībām — co-occurrence proposals confirmed by elder.
- 🆕 SERIES-006: Papildu sērijas — seed 104 → 467 → 119 → 103 with sources and confidence.
- 🆕 UI-008: Publiska sērijas demo karte — fictional 602 building grid for the landing page / lead magnet.
- 🆕 ADMIN-003: Jaunas mājas pašreģistrācija — VZD address code prefill, generator, compliance pack.
- 🆕 GOV-007: Atbilstības pakete katrai mājai — generated DPA, Art. 30, LIA, DPIA, notice when modules are enabled.
- 🆕 PAY-001: Rēķinu izrakstīšana mājām — manual invoicing via CoreDigify accounting, plan limits.
- 🆕 HEAT-010: Anonīmie sēriju salīdzinājumi — benchmarks only with k ≥ 5 buildings per series, opt-in.
- 🆕 SEC-006: PostgreSQL RLS — row-level security on vehicles, sightings, plate_lookups, audit_entries.
- 🆕 DEPLOY-002: Pāreja uz savu domēnu — if not done before printing; permanent 301 from kaimini.coredigify.com.

## Fāze 4 — Papildu
- 🆕 INFO-005: Sapulču organizators — agenda, proxy templates, shares/quorum calculator, protocol LV/RU (forms only, never binding).
- 🆕 HEAT-011: Siltummezgla žurnāls — manager-entered supply/return °C correlated with reports.
- 🆕 INFO-006: Plānoto darbu kalendārs — riser flushing, heating season, lift service notices.
- 🆕 HEAT-012: Stāvadu noslēgšanas kartītes un atslēgu reģistrs — elder-only valve locations and key holders; emergency fridge card.
- 🆕 INFO-007: Zemes dosjē — parcels, documents, request templates (document tracking, not legal advice).
- 🆕 INFO-008: Renovācijas izmaksu palīgs — BIS 602 typical-project €/m² + MK Nr. 880 support.
- 🆕 INFO-009: Dokumentu krātuve — contracts, protocols; AI summaries only later, on demand, capped.
- 🆕 MGR-003: Darbuzņēmēju piešķiršana — contractor dispatch for managers.
- 🆕 UI-009: Telegram bots — optional free notification channel.

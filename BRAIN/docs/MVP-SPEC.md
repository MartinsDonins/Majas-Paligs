# MVP-SPEC — Mājas Palīgs

> Build-ready scope for the MVP, split into three packages: **A Foundation**, **B Heating & outages**,
> **C Parking**. Status: authoritative as of 2026-10-17, revision 3 (Karen gate 2: B1 tenancy context
> allow-list + middleware order, B2 core gate L-core + split controllership, 17 suggestions).
> Architecture context: `BRAIN/PLANNING.md`.
> Design: `BRAIN/docs/DESIGN-SYSTEM.md`. No application code in this document; names are the
> contract that code must follow.

## 0. Conventions (all packages)

- **Tenant tables** (`building_id NOT NULL`): index starts with `building_id`; `UNIQUE(building_id, id)`;
  child FKs are composite `(building_id, x_id) REFERENCES x(building_id, id)`. Models in
  `App\Models\Tenant\*`-style groups use `BelongsToBuilding`. Platform tables (series, legal notices) have
  no `building_id`. `users` is global.
- **Cross-building access** happens only through the four sanctioned classes in PLANNING §4.2
  (`InviteResolver`, `UserScopedQuery`, `BuildingIterator`, `BreakGlass`). Everything else runs with a
  `CurrentBuilding` set or throws `MissingBuildingContext`. Cross-building user flows (`/join`, `/me/privacy`,
  WhoViewed, BuildingSwitcher, GdprExporter, AccountEraser, PIN limiter, Filament tenant resolution) are built on
  them — never on ad-hoc scope bypasses.
- **Setting the context**: `CurrentBuilding::set()` (throws `BuildingContextAlreadySet` when a different building
  is already set) and `CurrentBuilding::runFor($b, fn)` (set → run → restore) are called only by the seven
  allow-listed classes in PLANNING §4.2 (`SetCurrentBuilding`, `InviteResolver`, `UserScopedQuery::eachBuilding`,
  `BuildingIterator`, `RestoreBuildingContext`, `BreakGlass`, `PlatformBuildingContext`). `Building` is not a
  tenant model.
- **Gates**: `CoreGate` (L-core, PLANNING §5) and `ParkingGate` (L1/L2, C.0) are pure functions of
  `buildings.settings` + `building_privacy_notices`; both are checked in policy `before()`, model guards and
  routes/UI, and every gate change is audited.
- **Per-building counters** (`MP-YYYY-NNNN`, `PE-YYYY-NNNN`): table `building_counters(building_id, key, year,
  value)` PK(building_id, key, year); `CounterService::next()` does `INSERT … ON CONFLICT DO NOTHING` then
  `SELECT … FOR UPDATE` + increment inside the caller's transaction — gap-free, no duplicates under concurrency.
- **DB roles**: app code runs as `majas_app`; migrations as `majas_owner`; prune commands as `majas_retention`
  (A.2a).
- **IDs**: `bigint` identity internally; public URLs use building `slug` + route-scoped bindings
  (`Route::scopeBindings()`); never expose sequential IDs of sensitive rows to residents (use ULIDs for
  `incidents`, `sightings`, `manager_reports`, `parking_exports`, `data_exports`).
- **Timestamps** stored UTC (`timestamptz`), displayed Europe/Riga.
- **Enums** = PHP backed enums with `label()` reading `lang/{lv,ru}`; DB uses `text` + CHECK constraint.
- **Translatable labels** (catalogue) = JSONB `{lv, ru}` via spatie/laravel-translatable.
- **Livewire**: every public ID/role property `#[Locked]`; `$this->authorize()` inside every action; no
  plate/apartment/HMAC values in public properties; `/livewire/update` throttled 120/min per session.
- **Policies** check membership (`$user->membershipFor($building)`) **and** role. Deny by default.
- **Audit**: `AuditLogger::record(action, subject, subjectUserIds[], reason?, data[])` — never plaintext PII
  in `data`.
- **Tests**: Pest 5 against real PostgreSQL (`majas_test`), `--parallel`. Synthetic data only (plates like
  `AB1234` flagged as examples); CI guard (`scripts/personal-data-guard.sh`, self-tested) fails on `DOC/` tracked,
  `+371` numbers in any grouping (WhatsApp "+371 NN NNN NNN"), bare 2xxxxxxx / 6xxxxxxx numbers and plate shapes in
  docs/BRAIN.
- **Definition of done (every task)**: Pint clean, Larastan level 6 clean, tests green, LV+RU strings
  present, 320px layout checked, VERSION bumped (patch), BRAIN/TASK.md status updated.

---

## PACKAGE A — FOUNDATION

### A.1 Repository & tooling
- Laravel 13 at **repo root**, keeping `BRAIN/`, `DOC/` (ignored), `VERSION`, `.gitignore` (merge Laravel
  entries). `.gitattributes`: `* text=auto eol=lf`.
- Bootstrap (repo not empty): build own dev image first → `composer create-project laravel/laravel:^13`
  into a temp dir **inside the dev image** → copy without overwriting BRAIN/DOC/.gitignore → require packages.
  Never run composer in `composer:2.9` (PHP 8.5, no intl) — lock must resolve for PHP 8.4
  (`config.platform.php = 8.4.21`). Commit `composer.lock` and `package-lock.json`.
- `composer.json` require: `php ^8.4`, `ext-sodium`, `ext-intl`, `ext-pdo_pgsql`, `ext-gd`, `ext-redis`,
  `laravel/framework ^13.37`, `livewire/livewire ^4.4.7`, `filament/filament ^5.10.2`,
  `laravel/horizon ^5.50`, `barryvdh/laravel-dompdf ^3.1`, `spatie/laravel-translatable ^6.14`,
  `sentry/sentry-laravel ^4.29`, `bacon/bacon-qr-code` (current major), `intervention/image` (current major,
  GD driver), `mallardduck/blade-lucide-icons` (current). require-dev: `pestphp/pest ^5.3`,
  `pestphp/pest-plugin-laravel ^5.0`, `pestphp/pest-plugin-browser ^5.1`, `larastan/larastan ^3.13`,
  `laravel/pint ^1.32`. npm: `tailwindcss ^4.1`, `@tailwindcss/vite`, `vite-plugin-pwa`,
  `@fontsource-variable/inter`.
- **Runtime capability check**: the `base` image stage runs
  `php -r 'exit(function_exists("sodium_crypto_aead_xchacha20poly1305_ietf_encrypt") && defined("PASSWORD_ARGON2ID") ? 0 : 1);'`
  so a build without libsodium / Argon2 fails; `Unit/PlatformCapabilitiesTest` asserts the same at test time
  (PlateVault needs XChaCha20-Poly1305, PINs/temp codes need Argon2id).
- `scripts/dev.sh` wrapper (up, down, artisan, composer, npm, pest, pint, stan) hiding
  `docker compose exec` and `MSYS_NO_PATHCONV=1` for Git Bash.
- ADRs: `docs/ADR/0001-laravel-monolith.md` (Laravel, repo-root layout), `0002-ci-gated-deploy.md`,
  `0003-row-level-tenancy.md`, `0004-plate-vault.md`, `0005-auth-invite-email-pin.md`.

### A.2 Docker
**Dev `compose.yaml`**
| Service | Image / build | Notes |
|---|---|---|
| `app` | Dockerfile target `dev` (serversideup/php:8.4-fpm-nginx-trixie-v5.0.1 + intl gd exif bcmath pdo_pgsql redis pcntl + composer from `composer:2.9` + Xdebug installed but **off by default**, `XDEBUG_MODE=off`; enable per session via `.env`). Build args `USER_ID` / `GROUP_ID` (default 1000, from `id -u`/`id -g` via `scripts/dev.sh`) remap `www-data` with serversideup's `docker-php-serversideup-set-id` so files written into the WSL2 ext4 bind mount stay owned by the host user | port 8080; `vendor/` in named volume |
| `vite` | node:24-alpine, `npm run dev` | port 5173, `server.hmr.host=localhost`; `node_modules/` in named volume |
| `pgsql` | postgres:18.6-alpine | init script `docker/pgsql/00-bootstrap.sh` runs the **same** `database/sql/roles.sql` as production (A.2a) with dev passwords, for `majas` and `majas_test` |
| `redis` | redis:8-alpine | |
| `mailpit` | axllent/mailpit | SMTP 1025, UI 8025 (magic-link testing) |
| `horizon`, `scheduler` | reuse `app` image | `php artisan horizon`, `php artisan schedule:work` |

**Windows host (owner's machine):** keep the working copy on the **WSL2 ext4 filesystem**
(e.g. `~/www/Majas-Paligs` inside Ubuntu, opened via VS Code Remote-WSL), not on `C:\` / `/mnt/c` — bind-mounted
NTFS is slow and drops inotify events. If the repo must stay on `C:\www`, set Vite
`server.watch.usePolling: true` (interval 300 ms) via `VITE_POLLING=1`. First run after `up`:
`scripts/dev.sh composer install` and `scripts/dev.sh npm ci` populate the empty named volumes
(`vendor`, `node_modules`); `scripts/dev.sh init` does both + `key:generate` + `migrate --database=pgsql_owner --seed`.

**Production `Dockerfile`** (multi-stage): `base` (serversideup 8.4 + extensions) → `dev` → `assets`
(node:24-alpine `npm ci && npm run build`) → `vendor` (`composer install --no-dev --classmap-authoritative
--no-scripts`) → `prod` (non-root, app + vendor + public/build, `PHP_OPCACHE_ENABLE=1`, VERSION baked in,
`HEALTHCHECK` GET `/up`). Persistent volume for `storage/app/private`.
**nginx log format** (custom server config dropped into the serversideup image; verify the v5 include path at
DEPLOY-001.2): `log_format mp_noargs '$mp_ip - [$time_iso8601] "$request_method $uri" $status $body_bytes_sent
$request_time'` where `$mp_ip` is a `map` truncating IPv4 to /24 and IPv6 to /48 — never `$request`,
`$request_uri`, `$args` or `$http_referer`. Compose sets `logging: {driver: json-file, options: {max-size: 10m,
max-file: "5"}}` per service; Coolify log retention 14 days. `Referrer-Policy: no-referrer` set in nginx and in
the `SecurityHeaders` middleware.

**Production compose (Coolify Docker Compose resource), same image:**
| Service | Command | DB credentials | Notes |
|---|---|---|---|
| `migrate` | `php artisan migrate --database=pgsql_owner --force --isolated` | `DB_OWNER_*` only | one-shot, `restart: "no"`, `exclude_from_hc: true` |
| `web` | default (fpm + nginx) | `DB_*` = `majas_app` | `depends_on: migrate: service_completed_successfully`; `AUTORUN_ENABLED=true` (config/route/view/event cache) with `AUTORUN_LARAVEL_MIGRATION=false` |
| `worker` | `php artisan horizon` | `majas_app` | `AUTORUN_ENABLED=false`; after `migrate` |
| `scheduler` | `php artisan schedule:work` | `majas_app` + `DB_RETENTION_*` | `AUTORUN_ENABLED=false`; prune commands run in-process here (never queued to Horizon) |
Verify the exact serversideup v5 autorun variable names at DEPLOY-001.2 (the intent above is binding, the
names are not). Fallback if Coolify cannot run a one-shot service: run the same `migrate` command from CI over
a private tunnel before the deploy webhook — never give `web` the owner credentials.

**Coolify env (shared)**: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://kaimini.coredigify.com` (later own
domain), `APP_TIMEZONE=UTC`, `SESSION_DRIVER=redis`, `SESSION_COOKIE=__Host-mp_session`,
`SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN` unset, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`,
`DB_CONNECTION=pgsql`, `DB_USERNAME=majas_app`, `DB_PASSWORD`, `PLATE_ENC_KEY`, `PLATE_INDEX_KEY`, `INVITE_KEY`,
`APP_PREVIOUS_KEYS`, `SENTRY_LARAVEL_DSN` (GlitchTip), `MAIL_*` (EU transactional provider).
**Per service only:** `DB_OWNER_USERNAME=majas_owner`/`DB_OWNER_PASSWORD` (migrate),
`DB_RETENTION_USERNAME=majas_retention`/`DB_RETENTION_PASSWORD` (scheduler). DNS: SPF, DKIM, DMARC `p=reject`
for the sending subdomain before the pilot.

### A.2a Database roles & privileges
| Role | Attributes | Privileges | Used by |
|---|---|---|---|
| `majas_owner` | LOGIN, NOSUPERUSER, NOCREATEROLE (CREATEDB only in dev/CI for parallel test DBs) | owns database `majas`, schema `public` and every table, sequence, function and trigger | `migrate` service, `scripts/dev.sh migrate`, CI migration step |
| `majas_app` | LOGIN, NOSUPERUSER, NOCREATEDB, NOCREATEROLE, NOBYPASSRLS, **not owner** | `CONNECT` on DB, `USAGE` on schema; SELECT/INSERT/UPDATE/DELETE on normal tables; **SELECT/INSERT only** on `audit_entries`, `plate_lookups`; **SELECT only** on `audit_chain_heads`, `audit_chain_anchors`; no TRUNCATE, no CREATE; USAGE/SELECT on sequences; EXECUTE on app functions | `web`, `worker`, `scheduler` (non-prune work) |
| `majas_retention` | LOGIN, NOSUPERUSER, NOCREATEDB, NOCREATEROLE | `CONNECT`, `USAGE`; SELECT + DELETE on retention-managed tables (`audit_entries`, `plate_lookups`, `sightings`, `login_tokens`, `heating_reports`, `vehicles`, `users` tombstones); SELECT + **INSERT only** on `audit_chain_anchors` (no UPDATE/DELETE); SELECT on `audit_chain_heads`; SELECT + UPDATE(`status`, `status_reason`, `updated_at`) on `permits` and INSERT on `parking_officer_tasks` + `audit_entries` (for `VehicleRemovalService` during `prune:vehicles`, C.5) | `php artisan prune:*` commands scheduled in `scheduler` via connection `pgsql_retention` |
- **`database/sql/roles.sql`** (run once per cluster as superuser; dev/CI run the same file via
  `docker/pgsql/00-bootstrap.sh` / a CI step, production by the platform admin — MANUAL-TODO), idempotent:
  create the three roles with passwords passed as psql variables; `CREATE DATABASE majas OWNER majas_owner`;
  `REVOKE ALL ON DATABASE majas FROM PUBLIC`; `GRANT CONNECT ON DATABASE majas TO majas_app, majas_retention`;
  `\c majas`; `ALTER SCHEMA public OWNER TO majas_owner` (PG15+: `public` is owned by `pg_database_owner`,
  which is then `majas_owner` too — the explicit ALTER keeps it unambiguous); `REVOKE CREATE ON SCHEMA public FROM
  PUBLIC`; `GRANT USAGE ON SCHEMA public TO majas_app, majas_retention`; `CREATE EXTENSION IF NOT EXISTS
  btree_gist; … citext; … pg_trgm` **as superuser** (migrations repeat `CREATE EXTENSION IF NOT EXISTS`, a no-op).
  `Feature/Db/BootstrapTest` (CI): a fresh cluster bootstrapped only by `roles.sql` migrates from zero as
  `majas_owner` without errors.
- Grants live in migrations: a base migration sets `ALTER DEFAULT PRIVILEGES FOR ROLE majas_owner IN SCHEMA
  public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO majas_app`; every append-only table migration
  explicitly `REVOKE UPDATE, DELETE, TRUNCATE … FROM majas_app`; the audit migration additionally
  `REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON audit_chain_heads, audit_chain_anchors FROM majas_app` and
  `GRANT SELECT, INSERT ON audit_chain_anchors TO majas_retention`.
- **Trigger functions:** the chain INSERT trigger is `SECURITY DEFINER` (owner `majas_owner`,
  `SET search_path = pg_catalog, public`) because it writes `audit_chain_heads`; the DELETE guard
  (`audit_entries`, `plate_lookups`) is a separate **`SECURITY INVOKER`** function checking
  `current_user = 'majas_retention' AND session_user = 'majas_retention'` plus the age rule (in a DEFINER
  function `current_user` would be `majas_owner` and every prune would fail).
- Because `majas_app` is not the owner it cannot `ALTER TABLE … DISABLE TRIGGER`, drop or alter tables.
- Laravel connections: `pgsql` (app), `pgsql_owner` (migrations only), `pgsql_retention` (prune only).
  Arch test: `pgsql_retention` referenced only in `App\Console\Commands\Prune\*`; `pgsql_owner` nowhere in `app/`.

### A.3 CI (`.github/workflows/ci.yml`)
Services postgres:18.6-alpine (+ `psql -f database/sql/roles.sql` step with CI passwords — same file as
production) +
redis:8-alpine → setup-php 8.4 (intl, pdo_pgsql, redis, gd, exif, bcmath) → `composer install` → `pint --test`
→ `larastan` (level 6) → `pest --parallel` (test DBs created and migrated as `majas_owner`; privilege tests
connect explicitly as `majas_app` / `majas_retention`) → `composer audit` →
`npm ci && npm run build` → `npm audit --omit=dev` → lang key parity check (lv vs ru) → personal-data grep
guard → VERSION-changed check on PRs → `docker build --target prod`. On green `main`: curl Coolify deploy
webhook (`COOLIFY_WEBHOOK`, `COOLIFY_TOKEN`).

### A.4 Data model

**Platform tables**
| Table | Columns | Indexes / constraints |
|---|---|---|
| `building_series` | id, code (text), name jsonb, aliases text[], years_from int, years_to int, floors_typical int[], wall_material text, apartments_per_floor_typical int, notes jsonb, sources jsonb `[{url, title, accessed_at}]`, status `draft|published`, timestamps | UNIQUE(code) |
| `series_section_layouts` | id, series_id, code (e.g. `1-2-3-3`), apartments_per_floor, positions jsonb `[{position, side: left|right, apartment_type_code}]`, is_default bool, notes jsonb | UNIQUE(series_id, code) |
| `series_apartment_types` | id, series_id, code, rooms_count, area_m2_typical numeric(5,1), label jsonb | UNIQUE(series_id, code) |
| `series_rooms` | id, apartment_type_id, code (`kitchen|bath|wc|room1..room5|hall`), label jsonb, sort, radiator_count smallint, towel_dryer bool | UNIQUE(apartment_type_id, code) |
| `series_risers` | id, series_id, section_layout_id null, code_pattern (e.g. `H-P{position}-{room}`, `CW-{side}`), kind `heating|cold_water|hot_water|hot_water_circulation|sewage`, serves jsonb `[{position, room_code|null}]`, confidence `hypothesis|resident_confirmed|manager_confirmed|drawing_confirmed`, source_ref text | index(series_id, kind) |
| `legal_notices` | id, key (e.g. `parking.riga.current_rule`), jurisdiction, body jsonb, source_url, valid_from, updated_by | UNIQUE(key, valid_from) |
| `privacy_notice_versions` | id, kind `platform_account|building_template`, version, body jsonb (LV/RU; templates carry `{controller.*}` placeholders), covers_modules text[] (`account|core|heating|outages|parking`), published_at | UNIQUE(kind, version). `platform_account` = CoreDigify's own notice as controller of accounts; `building_template` = text the house adopts |

**Tenant & identity tables**
| Table | Columns | Indexes / constraints |
|---|---|---|
| `organizations` | id, name, kind `association|kopiba|manager|other`, reg_no null, timestamps | — |
| `buildings` | id, organization_id null, slug, name, address_line, address_search (normalized, unaccented: "puces iela 51"), postcode, city, address_code null, cadastre_designation null, series_id null, floors, year_built null, manager_name, manager_emergency_phone (organisation number, not personal), default_locale `lv|ru`, settings jsonb (see below), status `draft|active|archived`, timestamps | UNIQUE(slug), UNIQUE(address_code) WHERE NOT NULL, GIN trigram on address_search |
| `land_parcels` | id, building_id, cadastre_no, area_m2 null, verified bool, note | UNIQUE(building_id, cadastre_no) |
| `section_land_parcel` | building_id, section_id, land_parcel_id | PK(section_id, land_parcel_id), composite FKs |
| `sections` | id, building_id, number smallint, first_apartment_no, apartments_per_floor, floors, layout_code, series_section_layout_id null | UNIQUE(building_id, number), UNIQUE(building_id, id) |
| `apartments` | id, building_id, section_id, number, floor, position (primary cell), side, rooms_count, area_m2 null, series_apartment_type_id null, is_override bool, override_note null, retired_numbers int[] (numbers absorbed by a merge), archived_at null | UNIQUE(building_id, number), UNIQUE(building_id, section_id, floor, position), UNIQUE(building_id, id), FK(building_id, section_id) |
| `apartment_cells` | building_id, section_id, floor, position, apartment_id, is_primary bool | PK(section_id, floor, position); UNIQUE(apartment_id) WHERE is_primary; composite FKs. Generator writes one cell per apartment; a **merge** reassigns the absorbed cell(s) to the primary apartment (absorbed apartment row deleted only if it has no memberships/history, else archived), moves its rooms/riser links, appends its number to `retired_numbers`. The grid renders every cell; absorbed cells show the primary number with a "apvienots" join marker. |
| `rooms` | id, building_id, apartment_id, code, label jsonb, sort, radiator_count, towel_dryer | UNIQUE(apartment_id, code), UNIQUE(building_id, id), FK(building_id, apartment_id) |
| `risers` | id, building_id, section_id, code, kind, position null, side null, floors_from, floors_to, confidence, source_ref, series_riser_id null | UNIQUE(building_id, code), UNIQUE(building_id, id), index(building_id, section_id, kind) |
| `riser_links` | id, building_id, riser_id, apartment_id, room_id null | UNIQUE(riser_id, apartment_id, room_id) NULLS NOT DISTINCT, index(building_id, apartment_id), composite FKs |
| `users` | id, email citext null, email_verified_at, login_handle null, pin_hash null (Argon2id), pin_failed_count, pin_locked_until, pin_must_change bool, pin_temp_code_hash null, pin_temp_code_expires_at null, locale, display_name null (optional, never shown to other residents), platform_role null (`platform_admin`), text_size `normal|large`, theme `system|light|dark`, is_adult_confirmed_at, notice_version_accepted, last_login_at, MFA columns (Filament), remember_token, anonymised_at null, deleted_at, timestamps | UNIQUE(email) WHERE NOT NULL, UNIQUE(login_handle) WHERE NOT NULL. Deletion = **tombstone** (all identifying/credential columns nulled, `anonymised_at` + `deleted_at` set); the row stays while any audit entry references it and is hard-deleted by `prune:users` after the last referencing audit row is pruned |
| `login_tokens` | id, user_id null, email_hmac, token_hash, code_hash, purpose `login|email_change_old|email_change_new|step_up`, attempts smallint, expires_at, used_at, ip_hmac, created_at | index(token_hash), index(email_hmac, created_at) |
| `building_privacy_notices` | id, building_id, template_version_id (→ `privacy_notice_versions` kind `building_template`), controller_snapshot jsonb (name, contact at publication), covers_modules text[] (`core|heating|outages|parking`), published_at null, published_by, withdrawn_at null | index(building_id, published_at); the **current** notice = newest published, not withdrawn. Read by `CoreGate` / `ParkingGate`; shown at `/join` and `/privacy?b={slug}` |
| `apartment_invites` | id (ulid), building_id, apartment_id, code_hmac, max_uses (default 3), uses, expires_at (60 d), revoked_at, created_by, batch_ref | UNIQUE(code_hmac), index(building_id, apartment_id) WHERE revoked_at IS NULL |
| `apartment_memberships` | id, building_id, apartment_id, user_id, role `owner|tenant|family|contact`, status `pending|approved|rejected|ended`, invite_id null, approved_by, approved_at, rejected_reason null, ended_at, reconfirm_due_at, helper_consent_by_membership_id null (family helper consent), building_notice_id (the `building_privacy_notices` row shown at redemption) | partial UNIQUE(apartment_id, user_id) WHERE status IN ('pending','approved'), index(building_id, status), composite FKs |
| `building_roles` | id, building_id, user_id, role `building_admin|section_elder|parking_officer|volunteer|manager_viewer`, section_id null (required for section_elder), granted_by, granted_at, expires_at (+12 mo), revoked_at, mandate_ref null (house decision ref) | partial UNIQUE(building_id, user_id, role, section_id) WHERE revoked_at IS NULL |
| `audit_entries` | id bigserial, chain_key bigint (building_id, or 0 = platform chain), building_id null, actor_user_id null (plain bigint, **no FK** — user deletion can never cascade into the log), actor_role, action (dot-namespaced, e.g. `membership.approve`), subject_type, subject_id, subject_user_ids bigint[], reason_code null, reason_text null, data jsonb, ip_hmac, ua_hash, created_at, prev_hash bytea, hash bytea | index(chain_key, id), index(building_id, created_at), GIN(subject_user_ids). **BEFORE INSERT trigger** (owned by `majas_owner`): `pg_advisory_xact_lock(hashtextextended('audit:'||chain_key, 0))` → reads `audit_chain_heads` → sets `prev_hash`, `hash = sha256(prev_hash ‖ canonical row bytes)`, `created_at = now()` → updates the head. **BEFORE UPDATE**: always rejects. **BEFORE DELETE**: rejects unless `current_user = 'majas_retention'` and `created_at < now() - interval '24 months'`; pruning is in id order per chain and writes an anchor first |
| `audit_chain_heads` | chain_key PK, last_id, last_hash | written only by the trigger (SECURITY DEFINER); `majas_app` / `majas_retention` SELECT only |
| `audit_chain_anchors` | id, chain_key, pruned_through_id, anchor_hash, pruned_at | append-only (UPDATE/DELETE trigger-rejected); INSERT only by `majas_retention`, SELECT for app; `AuditChainVerifier` starts from the newest anchor and checks `anchor_hash` against the hash of row `pruned_through_id` recorded before pruning |
| `building_counters` | building_id, key (`manager_report|parking_export`), year, value | PK(building_id, key, year) — see §0 |
| `data_exports` | id (ulid), user_id, status `queued|ready|expired|failed`, path, expires_at, created_at | index(user_id) |
| `building_exports` | id (ulid), building_id, requested_by, status, path, expires_at, created_at | index(building_id) — structural/aggregate house export (A.8) |

`buildings.settings` keys (validated DTO): `modules{heating, outages, parking}`, `parking_enforcement_mode`
(`visible_permit|plate_list|both`), `parking_rules_adopted_at`, `parking_amnesty_until`, `controller{name, contact,
basis_ref}`, `dpa_signed_at`, `dpa_ref`, `dpa_scope` text[] (`core|heating|outages|parking`), `dpia_completed_at`,
`mandate_ref`, `sighting_quota{user_day, user_plate_day, building_day, building_photos_day, building_photo_mb}`
(10 / 3 / 100 / 50 / 500),
`share_with_manager{heating_floors, apartment_numbers, decided_ref}` (both default `false`; `apartment_numbers=true`
requires `heating_floors=true`; changes need building_admin + step-up and are audited),
`adhoc_report_valid_days` (7), `confirm_min_apartments` (2), `lookup_quota{user_hour, user_day, building_day}`,
`pin_limits{building_hour}` (100), `plate_index_key_version`.

### A.5 Domain services
- `BuildingGenerator::generate(SeriesTemplateData, GeneratorInput)` → sections, apartments, rooms, risers,
  riser_links in one transaction; idempotent `preview()` returns the grid DTO without writing.
  Numbering: `number = section.first_apartment_no + (floor-1)*apartments_per_floor + (position-1)`
  (direction param `ltr|rtl` for other buildings).
- `InviteCodeService`: generate 10 chars from `random_bytes` over the unambiguous alphabet
  `23456789ABCDEFGHJKMNPQRSTUVWXYZ` (no 0/O/1/I/L, ≈49 bits), display `XXXXX-XXXXX`; normalize (uppercase,
  strip spaces/dashes, Cyrillic look-alikes А В Е К М Н Р С Т Х → Latin; a typed O/0 or I/1/L is rejected
  with a hint, never silently mapped); HMAC-SHA256 with `INVITE_KEY`; redeem.
- `CurrentBuilding`: `set(Building)`, `runFor(Building, callable)`, `get()`, `id()`, `has()`, `forget()`;
  `set()` is a no-op for the same building and throws `BuildingContextAlreadySet` for a different one.
- `InviteResolver` (sanctioned cross-building lookup, PLANNING §4.2): `resolve(string $typed): ?ResolvedInvite`
  → normalize → HMAC → `apartment_invites` by `code_hmac` without building scope → validity check →
  **`CoreGate::status($building)->open`** (closed → `InviteResolution::HouseClosed`, no context set, no membership;
  the page shows the neutral "Šī māja vēl nav atvērta pieteikšanai — mēģiniet vēlāk" only to a holder of a *valid*
  code; printing is gated too, so this only happens after a gate regression) →
  `CurrentBuilding::set()` → DTO {invite_id, building_id, apartment_id, apartment number, section number,
  building_notice_id}. The only code that reads `apartment_invites` without a building. Throttle `invite` is
  applied **before** it runs. Never logs the typed code (not even at debug level).
- `CoreGate` (L-core, PLANNING §5): `status(Building): CoreGateStatus{open: bool, missing: string[]}` — open iff
  `controller.name`, `controller.contact`, `dpa_signed_at`, `dpa_ref`, `dpa_scope ⊇ {core, heating, outages}` and a
  current `building_privacy_notices` row with `covers_modules ⊇ {core, heating, outages}`. Used by `InviteResolver`,
  `InvitePolicy::print`, model guards on `ApartmentMembership` (create), `HeatingReport`, `IncidentConfirmation`,
  `Incident` (origin resident) (`saving` → `CoreGateClosed`), and `BuildingSettingsPage` (missing list). A
  settings change that closes the gate is audited and alerts building_admin + platform admin.
- `PlatformBuildingContext::run(Building, PlatformPurpose, callable)` (PLANNING §4.2 item 7): HTTP requires
  `platform_admin` + MFA session; console allows only `PlatformPurpose::Seed`; writes platform-chain audit
  `platform.building_context` {purpose, building_id}; delegates to `CurrentBuilding::runFor`.
  `BuildingGeneratorPage::generate()` and `BuildingResource` structure actions wrap their writes in it.
- `UserScopedQuery` (sanctioned, PLANNING §4.2): `for(User)`; `buildingIds()`, `memberships()`, `roles()`,
  `auditAboutMe(?cursor)`, `eachBuilding(callable)`. Returns DTOs/collections only; internal queries always
  `where user_id = $this->user->id` (or `subject_user_ids @> ARRAY[id]`) before leaving scope.
- `PinAuthService`: `attempt(handle, pin, ip)` → blocking limiters in order IP → handle → building (building
  from `UserScopedQuery::for($user)->buildingIds()`, unknown handle → only IP) → Argon2id verify (dummy
  hash for unknown handles) → one generic `PinLoginFailed` result for every failure kind; every failure also
  increments the **alert-only** `pin-global` monitor (never blocks). `setPin(user, pin)`
  enforces `WeakPinRule` (A.6). `issueTempCode(elder, user)` for resets.
- `MagicLinkService`, `StepUpService`, `AuditLogger`, `AuditChainVerifier`, `CounterService`,
  `GdprExporter` (job, `UserScopedQuery::eachBuilding`), `AccountEraser` (per building: end memberships,
  delete own vehicles via `VehicleRemovalService` (C.5) and declarations, keep heating reports & confirmations as
  house data pointing at the tombstone; then tombstone the user row; **never touches `audit_entries`**),
  `BuildingExporter` (job, A.8).
- Seeders (structure only, zero personal data; tenant writes wrapped in `PlatformBuildingContext::run(…, Seed)`):
  `Series602Seeder` (template with layout presets, apartment
  types, rooms, risers as `hypothesis`), `PucesIela51Seeder` (building, 5 sections × 9 floors × 4, numbering
  1..180, land parcel 0100 071 0114 `verified=false` + placeholder second parcel, all risers `hypothesis`),
  `DemoSeeder` (local only, synthetic users).

### A.6 Routes
| Method | Path | Handler | Middleware |
|---|---|---|---|
| GET | `/` | Landing (public, language choice) | web |
| GET | `/up` | Health JSON `{status, version}` | — |
| GET | `/privacy`, `/terms` | Public notice LV/RU | web |
| POST | `/locale/{locale}` | SetLocale | web, throttle |
| GET/POST | `/join` | `Onboarding\RedeemInvite` (QR opens `/join#c=CODE`; Alpine reads the fragment into the code field, clears it with `history.replaceState`, user taps "Turpināt" → POST) → `InviteResolver` → CoreGate → sets CurrentBuilding → Art. 13 notice (building + platform account) before role choice | guest-or-auth, throttle:invite; `GET /join?c=…` → **303 to `/join`**, value discarded |
| GET | `/join/role`, `/join/access` | `Onboarding\ChooseRole`, `Onboarding\ChooseAccess` | signed session state |
| GET/POST | `/login` | `Auth\EmailLogin` (always same response) | throttle:magic |
| GET | `/login/confirm` | confirm page (no state change); mail link is `/login/confirm#t=TOKEN`, Alpine copies the fragment into the hidden POST field and clears it | — ; legacy `GET /login/confirm/{token}` or `?t=` → 303 to `/login/confirm`, value discarded |
| POST | `/login/confirm` | consume token (body field) → session regenerate | throttle |
| POST | `/login/code` | 6-digit code login | throttle:code |
| GET/POST | `/login/pin` | `Auth\PinLogin` (handle + PIN) | throttle:pin-ip, PinAuthService limiters |
| GET/POST | `/login/pin/new` | `Auth\SetNewPin` (after temp code; only route reachable while `pin_must_change`) | auth (temp) |
| POST | `/logout` | | auth |
| GET | `/b/{building:slug}` | `Home\Dashboard` | group `resident` = auth, SetCurrentBuilding, member |
| GET | `/b/{building:slug}/me` | `Me\Apartment` (members, devices, language, text size) | `resident` |
| GET | `/b/{building:slug}/help` | `Elder\Panel` (approval queue, assisted onboarding, PIN reset) | `resident`, role:section_elder, step-up |
| GET | `/me/privacy` | `Me\Privacy` (export, delete, who viewed my data — across all the user's buildings via `UserScopedQuery`; no CurrentBuilding) | auth |
| GET | `/me/exports/{export}` | signed download (10 min) | auth, signed |
| — | `/platform/*` | Filament panel `platform` | platform_admin, MFA |
| — | `/manage/{building}/*` | Filament panel `manage` (tenant) | building role, MFA |

**Middleware order is binding.** The `resident` group (every `/b/{building:slug}/…` route, incl. Packages B and C)
runs `auth` → `SetCurrentBuilding` (sets the context from the route-bound `Building`, which is not a tenant model)
→ `member` (`$user->membershipFor($building)` approved or pending-read-only, or an active building role; otherwise
**404** — identical to an unknown slug) → route-specific `role:*` / `step-up`. The same group is registered as
persistent Livewire middleware so `/livewire/update` re-runs it. For the Filament `manage` panel, Filament resolves
the tenant first via `User::getTenants()` / `canAccessTenant()` (both via `UserScopedQuery::for($this)->roles()`,
no tenant query), then `tenantMiddleware([SetCurrentBuilding::class], isPersistent: true)` sets the context.

Rate limiters (named): `invite` 5/15 min per IP + 20/h per building (building known only after a
successful resolve; burst alert to elder) + 200/h global, `magic` 3/15 min per e-mail + 10/h per IP, `code` 5
attempts per token. **PIN** (blocking limiters must all pass, PLANNING §4.5): `pin-ip` 20/h per IP (IPv6 /64),
`pin-handle` 5 wrong → 15-min cooldown and 10/day → `pin_locked_until` (elder reset), `pin-building` 100/h
(`settings.pin_limits.building_hour`; alert elders + building_admin at 50% and on hit; PIN login for that
building paused 15 min). `pin-global` is a **monitor, not a limiter**: > 1000 failed attempts/h platform-wide or
> 300 unknown-handle attempts/h alert the platform admin (who may pause PIN login per building in the
`platform` panel); it never refuses a login. Every failure (unknown handle, wrong PIN, locked, building
paused) returns the **same status, body and redirect**: "Ieeja neizdevās. Pārbaudiet kodu un PIN. Ja neizdodas,
sazinieties ar kāpņu telpas vecāko." The calm cooldown screen is driven only by the IP/device counter.

**Alert payloads (PIN, invite, lookup, sighting) carry counts only**: building, limiter name, window, count,
threshold — never a handle, plate, bidx, code, IP, user id or apartment. `Feature/Notifications/AlertPayloadTest`
serialises every alert notification (mail + database channel) and asserts that.

### A.6a Secrets in URLs and logs
- Bearer secrets (invite codes, magic-link tokens) travel only in URL **fragments** or POST bodies (A.6 routes).
  Signed download URLs (`signature` query) are short-lived (5–10 min) and still kept out of logs.
- nginx `mp_noargs` log format (A.2) — no query strings, no referer, truncated IP.
- `Referrer-Policy: no-referrer`, `Cache-Control: no-store` on `/join`, `/login/*`, signed downloads.
- Sentry/GlitchTip `before_send` + `before_breadcrumb`: drop query string and fragment from `request.url` and
  breadcrumb URLs, replace path segments after `/login/confirm/`, `/me/exports/`, `/files/` with `[redacted]`,
  remove `c`, `t`, `token`, `code`, `pin`, `signature` keys from request data; `send_default_pii=false`.
- Laravel log context processor redacts the same keys; no request URL with a query is logged.
- `Feature/Security/SecretUrlLeakTest`: the invite QR payload and the magic-link mail use fragments; `GET
  /join?c=X` and `GET /login/confirm/{t}` answer 303 without echoing the value; a captured Sentry event
  (fake transport) and the Laravel log of a full join + login flow contain no code/token/signature;
  `Referrer-Policy` header present; the shipped nginx config contains `mp_noargs` and no `$request`/`$args`.

**`WeakPinRule`** (creation, reset, change): reject all-same digits; ascending/descending runs incl. wrap
(`123456`, `654321`, `890123`); repeated 2/3-digit patterns (`121212`, `123123`); first or last 4 digits forming
a year 1930–2015; valid `DDMMYY` / `YYMMDD` dates (1930–2015); digits of the user's apartment number repeated
(`145145`); a bundled list of the 200 most common 6-digit PINs. Message: "Šis PIN ir pārāk viegli uzminams —
izvēlieties citu." / "Этот PIN слишком легко угадать — выберите другой."

**Elder PIN reset** (`Elder\PinReset`): elder of the user's section only (step-up), cap 5/day per elder → issues
a temporary code (8 chars, unambiguous alphabet, Argon2id-hashed, 24 h, single-use) handed over in person/phone;
login with handle + temp code sets `pin_must_change` and allows only `/login/pin/new`; audit `pin.reset` with
`subject_user_ids=[user]`; notification to the user (if e-mail) and to the apartment's other approved members
("PIN šim dzīvoklim tika atjaunots ar vecākā palīdzību").

### A.7 Livewire components (resident app)
`Shell\AppShell` (top bar, bottom nav, offline banner; bottom-nav items and Home tiles are **module-dependent** —
"Stāvvieta" and the "Pārbaudīt auto / Manas caurlaides" tiles render only when `modules.parking` is on (gate L2)),
`Shell\BuildingSwitcher` (lists the user's memberships via `UserScopedQuery`), `Shell\LanguageToggle`,
`Onboarding\LanguagePick`, `Onboarding\RedeemInvite`, `Onboarding\ChooseRole`, `Onboarding\ChooseAccess`
(e-mail or PIN), `Onboarding\PendingApproval`, `Auth\EmailLogin`, `Auth\ConfirmLogin`, `Auth\PinLogin`,
`Home\Dashboard` (B/C cards appear when modules enabled), `Me\Apartment`, `Me\Privacy`, `Me\WhoViewed`,
`Elder\Panel`, `Elder\ApprovalQueue`, `Elder\AssistedOnboarding` (elder hands phone to neighbour: "Tas esmu
es" → set PIN), `Elder\PinReset`.

### A.8 Filament
- **platform** (`/platform`): `BuildingSeriesResource` (+ relation managers layouts / apartment types / rooms /
  risers), `BuildingResource` (non-tenant columns directly; structure actions inside `PlatformBuildingContext`),
  `BuildingGeneratorPage` (wizard with preview grid using the same Blade grid component as residents; `preview()`
  needs no context, `generate()` runs inside `PlatformBuildingContext::run($building, Generate, …)`), `UserResource`
  (minimal: lock, platform role), `LegalNoticeResource`, `PrivacyNoticeResource` (platform account notice +
  building templates), `PinPausePage` (manual per-building PIN pause after a `pin-global` alert),
  `AuditEntryResource` (cross-tenant, read-only; break-glass reason required). The platform panel has **no**
  tenant middleware; any tenant read/write there goes through `PlatformBuildingContext` or `BreakGlass`.
- **manage** (`/manage/{building}`, Filament native tenancy + our scope; `User implements HasTenants` with
  `getTenants()` / `canAccessTenant()` / `getDefaultTenant()` reading `UserScopedQuery::for($this)->roles()` only):
  `SectionResource`,
  `ApartmentResource` (overrides), `RiserResource` (confidence, links), `MembershipResource` (approve/reject/end),
  `BuildingRoleResource`, `InviteBatchPage` (generate PDF; disabled with the CoreGate missing-conditions list until
  L-core is open), `BuildingSettingsPage` (controller, DPA ref/scope, gate status for L-core / L1 / L2),
  `BuildingPrivacyNoticePage` (adopt a template, preview LV/RU with the controller filled in, publish/withdraw;
  building_admin + step-up, audited), `AuditEntryResource`
  (building-scoped), `BuildingExportPage` (building_admin + step-up, audit-logged: ZIP of JSON + CSV with
  sections, apartments structure + cells, risers + links + confidence, survey-round aggregates (riser/column level),
  incident history without reporter/confirmer identities, manager reports as **metadata + column-level aggregates
  only** (number, kind, period, section scope, sent/response dates, per-riser status/counts/coverage — the stored
  `snapshot` is **never** copied; per-floor lists, per-floor grids and apartment numbers are stripped even if the
  sharing flags were on), settings minus secrets and controller contact; **no memberships, users, plates, notes,
  floors or audit**; signed URL 10 min — the house takes its data to a new manager). Global search disabled on all sensitive resources; every `Select::relationship()`
  uses `modifyQueryUsing` scoped to the tenant. Theme via `make:filament-theme` importing `tokens.css`.

### A.9 Policies
`BuildingPolicy` (view: approved member or role; update: building_admin), `ApartmentPolicy`,
`MembershipPolicy` (approve: section_elder of that section or building_admin; never self-approve),
`BuildingRolePolicy` (grant: building_admin; platform_admin bootstrap), `InvitePolicy` (print: building_admin
**and** CoreGate open; `before()` denies below L-core), `MembershipPolicy::create` / `HeatingReportPolicy::create` /
`IncidentPolicy::confirm` (CoreGate open),
`AuditEntryPolicy` (building_admin sees building log; user sees entries where they are a subject),
`SeriesPolicy` (platform_admin only), `UserPolicy`.

### A.10 Screens (see DESIGN-SYSTEM §6)
Language pick → code entry → apartment confirmation + role → access method (e-mail / PIN) → pending state
→ Home. Mans dzīvoklis. Privātums (export / delete / who viewed). Elder panel. Invite letter PDF (A5 per
apartment: apartment number large, code `XXXXX-XXXXX` in mono, short URL, QR to `/join#c=…` (fragment — never
a query string), 3 steps LV + RU, controller name + "Privātuma paziņojums: {short URL}/privacy",
"Šis NAV BIS un NAV RNP · Это НЕ BIS и НЕ RNP", "Neizlieciet šo kodu publiski", detachable "Mans PIN" card).
Footer: version + privacy link.

### A.11 Acceptance criteria
1. `docker compose up` gives a working app at :8080, Mailpit at :8025; `scripts/dev.sh pest` is green.
2. Seeding produces Pūces 51 with 5 sections, 180 apartments numbered 1..180, stairwell 5 = 145..180, and
   for every apartment *n* below floor 9 the apartment above is *n+4* in the same section.
3. A resident can join with a valid invite code typed in lowercase, with spaces, or with Cyrillic К/М/Р
   look-alikes; an invalid code shows the friendly LV/RU error; the 6th attempt in 15 min shows a calm cooldown.
4. Redemption creates a **pending** membership; pending users see heating/outage read-only only; the elder
   of that stairwell approves in ≤3 taps; existing members of the apartment get notified.
5. E-mail login works via link (POST confirm) and via the 6-digit code; tokens are single-use, expire in
   15 min; the response for unknown e-mails is identical.
6. A user without e-mail can log in with handle + PIN; weak PINs are refused at creation; per-handle, per-IP,
   per-building and global limiters all fire; unknown handle, wrong PIN and locked handle give byte-identical
   responses; the elder of the user's section (only) resets via a single-use 24 h temp code that forces a new
   PIN, is audit-logged and notifies the apartment's members.
7. Every tenant model query without a current building throws; a user of building B gets 404 on every
   resident route and Livewire action of building A (middleware order `auth → SetCurrentBuilding → member`) and
   404/403 on every Filament resource of building A. The only scope lifts in `app/` are the four
   sanctioned classes and the only `CurrentBuilding::set()`/`runFor()` callers are the seven allow-listed ones;
   `/join`, `/me/privacy`, WhoViewed, BuildingSwitcher, Filament tenant resolution, export and deletion work
   through them and never return another user's rows; a second `set()` to a different building in one request
   throws.
8. LV/RU switch on every screen, remembered per user/cookie; no missing keys (CI).
9. Version visible in footer, `/up` JSON and `X-App-Version` header.
10. Self-service export produces a ZIP via signed link covering all the user's buildings; deletion ends
    memberships, removes own vehicles/declarations and **tombstones the `users` row** (credentials and identifiers
    nulled, `anonymised_at` set) — the audit actor is pseudonymised *through* the tombstone, **no audit row is
    updated or deleted**, and the hash chain still verifies; "who viewed my data" lists audit entries where the
    user is a subject, across buildings, showing actor role (never name).
11. As `majas_app`: UPDATE, DELETE, TRUNCATE on `audit_entries`/`plate_lookups` fail; INSERT, UPDATE, DELETE on
    `audit_chain_heads`/`audit_chain_anchors` fail; `ALTER TABLE audit_entries DISABLE TRIGGER ALL` fails (not
    owner). As `majas_retention`: UPDATE fails, DELETE of a row younger than 24 months fails, older rows can be
    pruned (the INVOKER guard sees `current_user = majas_retention`), anchors can be inserted but not updated or
    deleted, and the chain verifies from the anchor. Concurrent inserts never fork a chain (one chain per
    building + platform chain).
12. Merged apartments: a building with one override (two cells → one apartment) renders correctly, keeps
    riser links of both cells, and its absorbed number no longer redeems or appears in pickers.
13. Building export ZIP contains structure + aggregates and no user, membership, plate, note, controller-contact,
    per-floor or apartment-number fields — also when both `share_with_manager` flags were on when the included
    manager reports were generated.
14. Remember cookie is `__Host-mp_remember`; no `XSRF-TOKEN` cookie is set.
15. Invite PDF renders Latvian diacritics and Cyrillic; plaintext codes exist only in memory during render and
    appear in the QR only as a URL fragment; generation is audit-logged and served by a 10-min signed URL.
16. Lighthouse mobile: Accessibility ≥ 95, layout correct at 320px and 200% text size.
17. CI blocks merge on any failing step; deploy happens only via webhook after green main.
18. **Gate L-core:** with any of controller name/contact, DPA (signed, ref, scope ⊇ core/heating/outages) or a
    published building notice covering core/heating/outages missing, a valid code at `/join` gets the neutral
    "nav atvērta" screen and creates no membership, `InviteBatchPage` is disabled listing the missing conditions,
    and model guards refuse memberships, heating reports and incident confirmations from any path (incl.
    console); when all conditions are met the Art. 13 notice is shown before role choice and its id is stored on
    the membership; clearing a condition closes the gate again, alerts and audits.
19. No invite code, magic-link token or signed-URL signature appears in nginx access logs, Laravel logs,
    GlitchTip events or a `Referer` (A.6a).
20. A fresh Postgres cluster bootstrapped only by `database/sql/roles.sql` migrates from zero as `majas_owner`;
    the image build fails without sodium XChaCha20 or Argon2id.
21. Filament: the tenant menu lists only buildings where the user holds a manage role; opening another building's
    `/manage/{building}` returns 404 and never throws `MissingBuildingContext`; the platform generator writes
    only inside `PlatformBuildingContext` (audited; a non-platform_admin call is refused).

### A.12 Pest tests required
- `Unit/BuildingGeneratorTest` (602: 5×9×4 = 180, numbering, +4 rule, idempotent preview, overrides kept).
- `Unit/InviteCodeServiceTest` (alphabet, length, grouping, Cyrillic mapping, HMAC stable, no plaintext stored).
- `Unit/PinAuthServiceTest`, `Unit/WeakPinRuleTest` (dataset per deny class + accepted examples),
  `Unit/AuditHashChainTest` (per-building chains, anchor after prune, tamper detection, forged anchor rejected by
  the verifier), `Unit/CounterServiceTest`, `Unit/PlatformCapabilitiesTest` (sodium XChaCha20-Poly1305 function
  exists, `PASSWORD_ARGON2ID` defined), `Unit/CurrentBuildingTest` (same-building `set()` no-op, different →
  `BuildingContextAlreadySet`, `runFor` restores on success and exception).
- `Feature/Tenancy/MissingBuildingContextTest`, `Feature/Tenancy/CrossTenantTest` (dataset over every tenant
  model + every resident route + every Filament resource; two buildings generated via BuildingGenerator;
  **non-member of A → 404 on every `/b/{A}/…` route and Livewire update, with zero tenant-table queries before the
  404** (query log); member of B tampering a Livewire snapshot to A → 404/403),
  `Feature/Tenancy/MiddlewareOrderTest` (route list: every `/b/{building:slug}` route has `SetCurrentBuilding`
  immediately before `member`, `member` before any `role:*`/`step-up`; the same group is persistent Livewire
  middleware), `Feature/Tenancy/FilamentTenancyTest` (`getTenants` / `canAccessTenant` / `getDefaultTenant` run
  with no context and do not throw; return only buildings with an active manage role; resident-only member of A
  cannot open `/manage/A`; tenant switcher lists only own buildings; `SetCurrentBuilding` sets the Filament
  tenant), `Feature/Tenancy/ContextRepointTest` (a Livewire action or controller calling `set()` with another
  building throws; `PlatformBuildingContext` refuses non-platform_admin and non-MFA sessions, allows only `Seed`
  in console, audits purpose + building, restores the previous context),
  `Feature/Tenancy/CompositeForeignKeyTest` (DB rejects cross-building child rows), `Feature/Tenancy/QueuedJobContextTest`.
- `Feature/Tenancy/UserScopedQueryTest` (users U1, U2 in buildings A+B, U2 also in U1's apartment: every method
  returns only the bound user's rows; `auditAboutMe` never returns entries where only another user is subject;
  `eachBuilding` sets/restores CurrentBuilding and visits only the user's buildings; a job built for U1 cannot be
  re-pointed by payload tampering), `Feature/Tenancy/InviteResolverTest` (valid code sets CurrentBuilding to the
  invite's building; afterwards building B models are unreachable; revoked/expired/used-up/unknown → identical null
  path; closed CoreGate → `HouseClosed`, no context, no membership; no plaintext code stored or logged — Laravel
  log, fake Sentry transport and request URL checked).
- `Feature/Gdpr/CoreGateTest` (AC 18: matrix over each missing condition — controller name, contact, dpa_signed_at,
  dpa_ref, dpa_scope without `heating`, no notice, notice not covering `outages`, withdrawn notice; every write path
  refused (Livewire join, Filament membership create, console/model guard, heating report, "Man arī"); InviteBatchPage
  disabled; open gate → notice shown, `building_notice_id` stored; regression → closed, alert, audit).
- `Feature/Security/SecretUrlLeakTest` (A.6a, AC 19), `Feature/Notifications/AlertPayloadTest` (A.6).
- `Feature/Db/RolePrivilegesTest` (connects as `majas_app` and `majas_retention`: AC 11 cases),
  `Feature/Db/GrantsCoverageTest` (every table has the expected `majas_app` grants from
  `information_schema.role_table_grants`; append-only tables have no UPDATE/DELETE/TRUNCATE for app;
  `audit_chain_heads`/`audit_chain_anchors` SELECT-only for app; anchors SELECT+INSERT only for retention; `majas_app`
  owns nothing; `pg_proc.prosecdef` is false for the DELETE-guard functions and true only for the chain insert
  trigger), `Feature/Db/BootstrapTest` (AC 20), `Feature/Db/ConcurrentAuditInsertTest` (two connections insert in
  parallel → linear chain), `Feature/Db/CounterConcurrencyTest`.
- `Feature/Auth/MagicLinkTest` (GET does not consume, POST consumes, expiry, single-use, enumeration-safe,
  throttles), `Feature/Auth/LoginCodeTest`, `Feature/Auth/StepUpTest`,
  `Feature/Auth/PinLoginTest` (per-handle cooldown + daily lock; 21st attempt from one IP across 21 different
  handles blocked; 101st attempt in a building blocked + elder/admin alert at 50 and 100; global monitor: 1001
  failures from 60 rotated /64 prefixes alert the platform admin but a correct handle + PIN in an unaffected
  building still logs in; identical
  status/body/redirect for unknown vs wrong vs locked vs building-paused; dummy-hash verify invoked for unknown
  handles (spy, not wall clock); weak PIN rejected at set/reset; temp code single-use, 24 h expiry, forces
  `/login/pin/new`, audit `pin.reset`, members notified; elder of another section gets 403; reset cap 5/day),
  `Feature/Auth/SessionCookieTest` (`__Host-mp_session` + `__Host-mp_remember`, Secure, no domain, no `XSRF-TOKEN`).
- `Feature/Onboarding/RedeemInviteTest` (max_uses, expiry, revoked, pending, notification, throttles).
- `Feature/Membership/ApprovalPolicyTest` (elder only own section, no self-approve).
- `Feature/Gdpr/ExportTest` (multi-building user), `Feature/Gdpr/DeleteAccountTest` (tombstone, audit rows
  byte-identical before/after, chain verifies, WhoViewed of others still renders "Dzēsts lietotājs"),
  `Feature/Gdpr/WhoViewedTest`, `Feature/Gdpr/BuildingExportTest` (AC 13: no personal fields; fixture with both
  sharing flags on and a manager report whose snapshot holds per-floor rows and apartment numbers → ZIP contains
  neither; step-up, audit). *Implementation note (0.0.15):* these four live as separate `it()` cases in
  `Feature/Gdpr/GdprTest.php`; the parking export oracle cases are in `Feature/Parking/RegistrationOracleTest.php`.
- `Feature/Building/MergedApartmentTest` (cells, riser links, retired numbers).
- `Feature/Audit/AppendOnlyTest` (trigger), `Feature/I18n/LangParityTest`, `Feature/Health/VersionTest`.
- `Feature/Livewire/LockedPropertiesTest` (tampering an ID → 403).
- `Arch/TenancyArchTest` (tenant models use BelongsToBuilding; `Building` does not; no `env()` outside config; no
  dd/dump; `withoutBuildingScope` / `withoutGlobalScope(BuildingScope::class)` / `CurrentBuilding::forget` used
  **only** in `App\Tenancy\InviteResolver`, `App\Tenancy\UserScopedQuery`, `App\Tenancy\BuildingIterator`,
  `App\Platform\BreakGlass`; **`CurrentBuilding::set` / `CurrentBuilding::runFor` used only in
  `App\Http\Middleware\SetCurrentBuilding`, `App\Tenancy\InviteResolver`, `App\Tenancy\UserScopedQuery`,
  `App\Tenancy\BuildingIterator`, `App\Tenancy\Jobs\RestoreBuildingContext`, `App\Platform\BreakGlass`,
  `App\Platform\PlatformBuildingContext`** (implemented as a custom Larastan rule over static calls and
  container-resolved instances, so `app(CurrentBuilding::class)->set()` is caught too); `UserScopedQuery::for`
  called only from the allow-listed callers incl. `App\Models\User`;
  `pgsql_retention` only in `App\Console\Commands\Prune\*`; `pgsql_owner` nowhere in `app/`).
- `Browser/OnboardingSmokeTest` (code → role → e-mail via Mailpit → pending → elder approves).

---

## PACKAGE B — HEATING & OUTAGES

### B.1 Data model
| Table | Columns | Indexes / constraints |
|---|---|---|
| `survey_rounds` | id, building_id, title jsonb, kind `heating_check`, section_ids int[] null (null = whole building, building_admin only; validated — B.1 "`section_ids` integrity"), starts_at, ends_at, status `draft|open|closed`, created_by | index(building_id, status) |
| `heating_reports` | id, building_id, apartment_id, room_id, survey_round_id null, status `cold|lukewarm|warm|overheated`, temperature_c numeric(3,1) null CHECK 0–40, triage `air_suspected|valve_closed|whole_flat|unknown` null, note_private text null (≤280, admin/elder only), source `self|proxy|phone`, reported_by_user_id, reported_at, valid_until, superseded_at null, hidden_at null, hidden_by null, hidden_reason null | partial UNIQUE(room_id, coalesce(survey_round_id,0)) WHERE superseded_at IS NULL AND hidden_at IS NULL; index(building_id, reported_at); composite FKs |
| `incidents` | id ulid, building_id, kind `water_cold|water_hot|heating_none` (Phase 2 adds `lift|lights|door_lock|other`), origin `resident|notice`, section_id null, riser_id null, column_position null, floors int4range null, scope_key text (`riser:{id}` \| `col:{section_id}:{position}` \| `section:{id}` \| `building`), is_open bool (generated: status NOT IN resolved/rejected), status `reported|confirmed|manager_notified|in_progress|resolved|rejected`, started_at, expected_end_at null, resolved_at null, reason jsonb null (notice text LV/RU, admin-written), manager_ref null, manager_reply jsonb null (≤160 chars, admin-entered), opened_by, timestamps | index(building_id, status, kind); **de-dup key** partial UNIQUE(building_id, kind, scope_key) WHERE is_open AND origin='resident' |
| `incident_confirmations` | id, building_id, incident_id, apartment_id, user_id, source `self|proxy|phone`, created_at | UNIQUE(incident_id, apartment_id) |
| `riser_status` (projection) | building_id, section_id, riser_id, round_key (`round:{id}` or `rolling`), status `cold|lukewarm|warm|overheated|mixed|none`, confirmed bool, distinct_apartments, floors_covered smallint, status_counts jsonb `{cold, lukewarm, warm, overheated}` (apartment counts), coverage_total (apartments on riser), conflict bool, checked_state null, disputed bool, whole_riser_under bool, **restricted:** floors_reported smallint[], top_floor_only bool, updated_at | PK(riser_id, round_key), index(building_id, round_key) |
| `column_status` (projection) | building_id, section_id, position, round_key, worst_confirmed_status, has_unconfirmed bool, distinct_apartments, floors_covered smallint, disputed bool, updated_at | PK(section_id, position, round_key) |

**`section_ids` integrity** (`survey_rounds.section_ids`, `manager_reports.section_ids` drive elder authorization
via `section_ids ⊆ own sections`): (1) the `SectionScope` DTO validates against `Section::pluck('id')` of the
current building (non-empty when not null, no duplicates, sorted); (2) a `BEFORE INSERT OR UPDATE` constraint
trigger `assert_section_ids_in_building()` (SECURITY INVOKER) raises unless every element exists in `sections`
with `building_id = NEW.building_id`; (3) `manager_reports.section_ids` is NOT NULL (whole building = all ids
listed explicitly, so "null = everything" can never widen an elder's report); `survey_rounds.section_ids = null`
(whole building) may be set only by building_admin. A companion `BEFORE DELETE` trigger on `sections` refuses
deleting a section still referenced by either array.

**Restricted fields** (`floors_reported`, `top_floor_only`, any per-apartment/per-floor state) are never loaded
into resident components. Two read models enforce this: `ResidentRiserView` (DTO without restricted fields —
the only type `Heating\Grid`/`RiserList`/`RiserSummarySheet`/`DigestCard`/Home mini map accept in resident mode)
and `StaffRiserView` (adds restricted fields; constructed only after `HeatingReportPolicy::viewApartments($user,
$section)` passes).
| `riser_checks` | id, building_id, riser_id, result `normal|air_vented|fixed|escalated`, checked_at, recorded_by, note_private | index(building_id, riser_id, checked_at) |
| `riser_disputes` | id, building_id, apartment_id, room_id null, riser_id, status `open|resolved|rejected`, resolved_by, created_at | index(building_id, riser_id) WHERE status='open' |
| `manager_reports` | id ulid, building_id, number (`MP-{YYYY}-{NNNN}` via `CounterService` key `manager_report`), kind `heating|outage`, section_ids int[] NOT NULL (report scope, validated — B.1 "`section_ids` integrity"), params jsonb, sharing jsonb (flags as at generation), snapshot jsonb (exactly what was exported — floors/apartments only if the flags were on), generated_by, generated_at, sent_at null, manager_ref null, response jsonb null | UNIQUE(building_id, number) |

### B.2 Aggregation rules (`RiserAggregator`)
1. Input per riser: the latest non-hidden, non-superseded report per apartment for rooms linked to the
   riser, within the round (or `valid_until` window of 7 days for rolling).
2. `distinct_apartments` = count of apartments; `confirmed` = distinct_apartments ≥ `confirm_min_apartments` (2).
3. Status = majority among apartments (cold+lukewarm grouped as "under" for majority, then the more frequent
   of the two); if top share < 60% → `mixed` + `conflict=true`.
4. `top_floor_only` = all "under" reports from floors ≥ 8 → tip about air in riser **for staff only**
   (residents always see the generic tip on any under-status riser — the flag + count would reveal floors).
4a. `floors_covered` = count of distinct floors with a valid report; `status_counts` = apartments per state.
4b. `whole_riser_under` = confirmed AND distinct_apartments ≥ 3 AND ≥ 80% of reporting apartments are
   cold/lukewarm AND reports span both floors 1–4 and 6–9 AND NOT top_floor_only (boolean only; feeds the
   manager triage count "viss stāvads auksts").
5. An open `riser_dispute` → `disputed=true`; column renders "Pārbaudāms" and is excluded from manager export.
6. A `riser_check` newer than the reports sets `checked_state` (shown as "Pārbaudīts").
7. Recompute via `RecomputeRiserStatus` (unique per building, debounced 10 s) on every report, hide, check,
   dispute; `column_status` derived from risers whose links cover that position.

### B.2a Outage scope (`OutageScopeResolver`)
Residents never choose a riser. Input: apartment, kind (`water_cold → cold_water`, `water_hot → hot_water`,
`heating_none → heating`).
1. Load the apartment's risers of that kind via `riser_links`.
2. **Exactly one** → scope `riser:{id}`. **More than one** (e.g. kitchen + bath, side-grouped) → ask one
   question "Kur nav ūdens? Virtuvē / Vannas istabā / Visur" (rooms derived from the links); a room → that
   riser; "Visur" → every linked riser (one confirmation per incident). **None / links unknown** → column scope
   `col:{section_id}:{position}` (primary cell); no section/position → `section:{id}`.
3. Before creating, join an existing open incident of the same kind covering the apartment, in order:
   an `origin=notice` incident whose scope covers it (riser, column, section or building) → otherwise the
   resident incident with the same de-dup key `(building_id, kind, scope_key)`.
4. Create with `INSERT … ON CONFLICT (building_id, kind, scope_key) WHERE is_open AND origin='resident' DO
   NOTHING`, then select — concurrent first reports end in one incident.
5. Confirmation = `incident_confirmations` UNIQUE(incident_id, apartment_id) → "Man arī" idempotent.

### B.3 Routes
| Method | Path | Handler |
|---|---|---|
| GET | `/b/{slug}/heating` | `Heating\Grid` (mode=heating, stairwell tab, round chip, grid/list toggle) |
| GET | `/b/{slug}/water` | `Heating\Grid` (mode=outage) |
| GET | `/b/{slug}/incidents/{incident}` | `Outage\Detail` (status, count, manager reply, "Man arī") |
| — | `/manage/{building}/survey-rounds` | Filament `SurveyRoundResource` |
| — | `/manage/{building}/incidents` | Filament `IncidentResource` (notice create, status, manager ref/reply) |
| — | `/manage/{building}/manager-reports` | Filament `ManagerReportResource` (generate, mark sent, response) |
| GET | `/b/{slug}/reports/{report}.pdf` / `.csv` | signed (10 min), building_admin, or section_elder when `report.section_ids ⊆` own sections |

### B.4 Livewire components
`Heating\Grid` (server-rendered once; tabs and sheets via Alpine; `wire:poll.visible.60s`),
`Heating\ReportSheet` (rooms → status → confirm → thank-you with riser count; "Visās telpās vienādi"; **no offline
queue in the MVP** — Livewire `/livewire/update` requests carry CSRF + snapshot checksums and cannot be replayed
by a service worker; offline, the sheet keeps the selection in Alpine memory, disables "Saglabāt" and shows "Nav
savienojuma — mēģiniet vēlreiz, kad būsiet tiešsaistē"; a JSON endpoint with CSRF + idempotency key for queued
saves is a Phase 2 option),
`Heating\RiserSummarySheet`, `Heating\RiserList` (accessible list mode, worst first),
`Heating\RoundCard` (Home: "aizpildījuši 21 no 36"), `Outage\Banner` (`wire:poll.30s`),
`Outage\ReportSheet` ("Nav ūdens" → cold/hot/both → since when → auto-join open incident),
`Outage\MeTooButton`, `Elder\ProxyReport` (report on behalf, source=proxy|phone),
`Elder\NoticeComposer` (outage notice to a riser/column/section), `Share\DigestCard` (WhatsApp-ready text +
`https://wa.me/?text=` share; aggregates only), `Heating\DisputeButton` ("Mans radiators nav šajā stāvadā").

### B.5 Policies
`HeatingReportPolicy` (create: approved member of that apartment, or elder/volunteer proxy **for an apartment in
their section**; view own; `viewApartments(user, section)` = per-apartment/per-floor data: building_admin for any
section, section_elder **only when `section.id` ∈ their active section_elder roles**; volunteers never; hide:
building_admin), `SurveyRoundPolicy` (manage: building_admin, section_elder for own section), `IncidentPolicy`
(confirm: approved member whose apartment is covered by the scope; create notice: elder for own section /
admin any; update status/reply: building_admin), `ManagerReportPolicy` (generate/download: building_admin any
scope; section_elder only when `section_ids ⊆` own sections; elder reports never include other sections),
`RiserCheckPolicy` (building_admin), `BuildingSettingsPolicy::updateSharing` (building_admin + step-up).

### B.6 Screens
Heating grid (resident: column aggregates + own cell ring; admin, and elder for own section: per-apartment
cells), report sheet, riser summary sheet (resident: status, counts, "X/9 stāvi" as a plain progress bar — never
a per-floor segment bar), list mode, outage banner + detail, "Nav ūdens" flow (+ room question only when
several risers), Home cards (round, outage, mini map), elder proxy report, notice composer, Filament
round/incident/report pages, manager PDF (A4: header with report number, building, period, method + coverage
disclaimer; riser table with status, confirmed, apartments, floors covered, coverage, triage counts incl.
"viss stāvads auksts", confidence; **column-level** black-and-white-safe grid snapshot always; per-floor list +
per-floor snapshot only with `heating_floors`; apartment numbers only with `apartment_numbers`; "Aplikācija nav
avārijas kanāls" footer; manager emergency phone). No private notes in the package.

### B.7 Acceptance criteria
1. A resident reports a cold kitchen radiator in ≤4 taps and sees "Paldies! … Jau ziņojuši N dzīvokļi Jūsu stāvadā".
2. One report shows the column as **unconfirmed** (outline); a second report from another apartment on the
   same riser turns it **confirmed**; two reports from the same apartment never confirm.
3. Residents never see which apartment or which floor reported: resident payloads (HTML, Livewire snapshot,
   CSV/PDF none) contain no `floors_reported`, per-floor segments, `top_floor_only` or apartment ids of others.
   building_admin sees per-apartment cells everywhere; a section_elder only in their own section (other
   sections render the resident view; per-apartment endpoints return 403).
4. Coverage text "dati no X no 9 stāviem" is correct; columns with no data render "Nav datu", never green.
5. An open dispute freezes the column as "Pārbaudāms"; a riser check shows "Pārbaudīts".
6. "Nav ūdens" auto-joins the open incident with the same de-dup key (B.2a) or a covering notice; the room
   question appears only when the apartment links to more than one riser of that kind; two simultaneous first
   reports create one incident; "Man arī" is idempotent per apartment.
7. An elder notice (riser/column/section, reason, expected end) shows a banner only to affected apartments, in LV and RU.
8. Manager PDF/CSV carry a gap-free stable number (`MP-YYYY-NNNN`, concurrent generation never duplicates),
   aggregates only incl. the "viss stāvads auksts" triage count, no reporter identity, no notes; per-floor data
   and the per-floor snapshot only when `share_with_manager.heating_floors=true`; apartment numbers only when
   `apartment_numbers=true` (which requires heating_floors); the stored snapshot contains only what was
   exported; an elder can generate/download only reports scoped to their own section(s); generation
   audit-logged; CSV formula-injection safe.
9. Grid loads with ≤3 queries (projection tables), usable on a slow 3G profile; polling only when visible.
10. Ad-hoc reports expire after 7 days; heating reports pruned at season end + 1 month.
11. A survey round or manager report with a foreign, unknown or duplicate section id is rejected by the DTO and by
    the database; an elder cannot widen a report's scope by editing `section_ids`.
12. Offline, the report sheet never pretends to queue: "Saglabāt" is disabled with the "mēģiniet vēlreiz" banner and
    the selection survives reconnecting.

### B.8 Pest tests required
`Unit/RiserAggregatorTest` (dataset: 0/1/2 apartments, same-apartment duplicates, mixed, top-floor-only,
disputed, checked, whole_riser_under), `Unit/OutageScopeResolverTest` (0/1/2+ risers of a kind, "Visur",
unknown links → column, covering notice wins, merged apartment), `Feature/Outage/IncidentDedupConcurrencyTest`,
`Feature/Heating/ReportFlowTest`,
`Feature/Heating/SectionIdsIntegrityTest` (survey round / manager report with a section id of building B, an
unknown id, duplicates, empty array → rejected by DTO **and** by the DB trigger on a raw insert; elder of section 5
submitting forged `section_ids` [1,5] → 403; deleting a referenced section refused),
`Feature/Heating/PrivacyOfReportersTest` (resident payloads — rendered HTML, Livewire snapshot JSON, digest text —
contain no apartment ids of others, no `floors_reported`, no per-floor segment markup, no `top_floor_only`;
a section_elder of stairwell 1 viewing stairwell 5 gets the resident payload and 403 on per-apartment/proxy
endpoints; volunteer gets resident payload), `Feature/Heating/SurveyRoundTest`,
`Feature/Heating/ProxyReportTest` (own section only), `Feature/Outage/MeTooIdempotencyTest`,
`Feature/Outage/NoticeTargetingTest`, `Feature/Heating/ManagerReportTest` (number sequence + concurrency,
snapshot = exported fields only; flags matrix: none → no floors/apartments, heating_floors → floors + per-floor
snapshot, apartment_numbers without heating_floors rejected by DTO; elder cross-section generate/download 403;
whole-riser triage count; no notes; CSV injection), `Feature/Heating/SharingSettingsTest` (step-up, audit), `Feature/Heating/RetentionTest`, `Feature/Tenancy/CrossTenantTest` extended
with all B models/routes, `Browser/HeatingReportSmokeTest`.

---

## PACKAGE C — PARKING

### C.0 Go-live gate — two levels (`ParkingGate`)
| Level | Conditions (all recorded in `buildings.settings`) | Unlocks |
|---|---|---|
| **L0** (default) | — | `parking_lots`, legal banner / sign drafts. **No personal data.** |
| **L1 — processing** | **L-core open** (controller name + contact, DPA signed + ref, published building notice covering core/heating/outages — A.5 `CoreGate`) **and** `dpa_scope ∋ parking` | Any write to `vehicles`, `permits` (incl. `vehicle_id`), `apartment_parking_declarations`, `sightings`, `plate_lookups`; `PermitImportPage`; officer/admin Filament resources. Residents still see nothing. |
| **L2 — resident-facing** | L1 + `dpia_completed_at` + the current published `building_privacy_notices` row with `covers_modules ⊇ {parking}` + `mandate_ref` | Enabling `modules.parking`: lookup, sightings, declarations, Manas caurlaides, Change vehicle, campaign progress, bottom-nav item, Home tiles. |

Enforcement (defence in depth, all three required):
1. **Policies** — every Parking policy (`VehiclePolicy`, `PermitPolicy`, `PlateLookupPolicy`, `SightingPolicy`,
   `DeclarationPolicy`, `ParkingExportPolicy`, `ParkingAnalyticsPolicy`, `PermitImportPolicy`) has a `before()`
   that denies when the required level is not met (writes → L1, resident abilities → L2).
2. **Model guard** — `saving`/`deleting` listeners on Vehicle, Permit, Declaration, Sighting, PlateLookup throw
   `ParkingGateClosed` below L1 (catches seeders, console, imports, tinker). Retention deletes are exempt.
3. **Routes/UI** — resident parking routes 404 and nav/tiles hidden below L2; Filament resources hidden below L1
   with an explanation panel listing missing conditions.
Gate field changes are building_admin + step-up and audit-logged. Losing a condition (e.g. `dpa_signed_at`
cleared) drops the level at once: `modules.parking` is switched off automatically and writes are refused.

### C.1 Data model
| Table | Columns | Indexes / constraints |
|---|---|---|
| `parking_lots` | id, building_id, name, capacity_estimate, signage text null, notes, sort | UNIQUE(building_id, name) |
| `vehicles` | id **ulid** (generated app-side before sealing — used in AEAD associated data), building_id, apartment_id, plate_ciphertext bytea, plate_bidx bytea(32), plate_key_version smallint, country char(2) default 'LV', is_company_car bool, status `pending|active|rejected|removed` (resident declarations always start `pending`; only an officer/admin action sets `active`), conflict bool (officer-only: an active vehicle with the same bidx exists elsewhere in the building), source `self|officer|seed_list`, declared_by_user_id null, verified_at null (officer verified), reconfirm_due_at, removed_at null, timestamps | partial UNIQUE(building_id, plate_bidx) WHERE status='active'; **UNIQUE(building_id, apartment_id, id)**; index(building_id, apartment_id); composite FK (building_id, apartment_id) → apartments |
| `permits` | id, building_id, apartment_id, number text, type `main|guest`, vehicle_id ulid null, issued_by text, valid_from date, valid_to date null, status `active|returned|lost|revoked|expired`, status_reason null (`vehicle_removed|account_deleted|retention|officer`), replaced_by_permit_id null, note_private null, timestamps | CHECK (type='guest' OR vehicle_id IS NOT NULL OR status<>'active'); EXCLUDE USING gist (building_id WITH =, number WITH =, type WITH =, daterange(valid_from, coalesce(valid_to,'infinity')) WITH &&) WHERE (status='active'); **composite FK (building_id, apartment_id, vehicle_id) → vehicles(building_id, apartment_id, id) ON DELETE SET NULL (vehicle_id)** — a permit can never point at another apartment's vehicle; composite FK (building_id, apartment_id) → apartments |
| `parking_officer_tasks` | id, building_id, kind `permit_without_vehicle|vehicle_conflict|vehicle_verify`, permit_id null, vehicle_id ulid null, status `open|done|dismissed`, created_at, done_by null, done_at null | index(building_id, status); visible to parking_officer/building_admin only; payload holds ids only, no plate |
| `apartment_parking_declarations` | id, building_id, apartment_id, declared_at, has_no_vehicle bool, declared_by_user_id, confirmed_by null | UNIQUE(apartment_id) |
| `plate_lookups` | id, building_id, user_id, plate_bidx, result bool, reason_code `blocking|unknown_car|own_check|other|declaration|sighting`, created_at | index(building_id, created_at), index(building_id, plate_bidx), append-only trigger (same pattern as audit: UPDATE always rejected, DELETE only by `majas_retention` for rows older than 6 mo); no UPDATE/DELETE grant for `majas_app` |
| `sightings` | id ulid, building_id, parking_lot_id, plate_ciphertext, plate_bidx, plate_key_version, country, seen_at, reporter_user_id, reporter_apartment_id, matched_registered bool (**officer/admin only** — never in reporter payloads), photo_path null, photo_bytes int null, photo_deleted_at null, status `open|disputed|dismissed|resolved`, created_at | index(building_id, plate_bidx, seen_at), index(building_id, created_at) |
| `plate_sighting_stats` (projection) | building_id, plate_bidx, sightings_30d, distinct_reporter_apartments_30d, days_seen_60d, last_seen_at, is_repeat bool | PK(building_id, plate_bidx) |
| `parking_exports` | id ulid, building_id, number (`PE-{YYYY}-{NNNN}` via `CounterService` key `parking_export`, assigned at request), kind `police|manager`, format `csv|pdf`, requested_by, requested_at, approved_by null, approved_at null, generated_at null, row_count null, expires_at | UNIQUE(building_id, number); CHECK approved_by <> requested_by |

`PlateVault`: `seal(plate, VaultContext{buildingId, table, rowId})` → {ciphertext (XChaCha20-Poly1305-IETF via
sodium with `PLATE_ENC_KEY`, random 24-byte nonce, **associated data** = `"mp:plate:v{key_version}|b:{building_id}|
t:{table}|r:{row_id}"`), bidx = HMAC-SHA256(HKDF-SHA256(`PLATE_INDEX_KEY`, info "plate-bidx", salt building_id),
normalized), key_version}; `open(ciphertext, VaultContext)` fails if the row was moved to another row, table or
building. `open()` only inside policy-gated reveal/export/own-vehicle paths. Row ids (vehicles, sightings) are
ULIDs generated before sealing.
**Rotation** (`plates:rekey {building?}`): per building, in **one transaction** holding
`pg_advisory_xact_lock('plates:'||building_id)`: re-seal ciphertext and recompute bidx for every row of
`vehicles` and `sightings` with the new version, set `settings.plate_index_key_version`, rebuild
`plate_sighting_stats`. The partial UNIQUE(building_id, plate_bidx) stays valid because all rows of the building
switch version atomically (a duplicate found during re-key aborts that building's transaction and alerts the
platform admin). Lookups always compute bidx with the building's current version; old `plate_lookups` rows keep
their old bidx and simply age out (6 mo).

### C.2 Routes
| Method | Path | Handler |
|---|---|---|
| GET | `/b/{slug}/parking` | `Parking\Lookup` (+ legal banner, completeness %) |
| GET | `/b/{slug}/parking/mine` | `Parking\MyPermits` (own permits, vehicles, "Mainīt auto", delete my plate) |
| GET | `/b/{slug}/parking/declare` | `Parking\DeclarationWizard` (plates or "mums nav auto") |
| GET | `/b/{slug}/parking/sighting` | `Parking\SightingSheet` |
| GET | `/b/{slug}/parking/progress` | `Parking\CampaignProgress` (per-stairwell rings, aggregate) |
| GET | `/files/sightings/{sighting}/photo` | authorised controller, signed 5 min |
| — | `/manage/{building}/parking/*` | Filament: `ParkingLotResource`, `PermitResource`, `VehicleResource` (masked, Reveal action), `SightingResource`, `ParkingAnalyticsPage`, `ParkingExportResource` (request/approve/download), `PermitImportPage` (issuer list CSV), `ParkingSignPage` (Art. 14 sign PDF) |

### C.3 Livewire components
`Parking\Lookup` (single plate input, exact match, reason chip, result card neutral, rate-limit countdown),
`Parking\MyPermits`, `Parking\ChangeVehicleWizard` (new plate → return old permit → pending officer),
`Parking\DeclarationWizard`, `Parking\SightingSheet` (lot select, time prefilled, optional camera capture,
client-side downscale, "dzēsts pēc 30 dienām"), `Parking\SightingDispute` ("Esmu viesis"),
`Parking\CampaignProgress`, `Parking\LegalBanner`, `Parking\PrivacyBox` ("Kas redz šo?").
All resident parking components mount only at gate L2; officer/admin Filament pages only at L1.

### C.4 Policies
Every policy below first applies `ParkingGate` in `before()` (C.0). `PermitImportPolicy` (building_admin or
parking_officer, L1). `DeclarationPolicy` (approved member of that apartment, L2; officer/admin L1).
`VehiclePolicy` (create/update/delete own: approved owner/tenant/family of that apartment (tenant only own
vehicle), officer/admin any; reveal: parking_officer/building_admin with step-up), `PermitPolicy`
(manage: parking_officer/building_admin; view own apartment's), `PlateLookupPolicy` (approved members only,
module enabled), `SightingPolicy` (create: approved member; view: reporter own, officer/admin all; photo:
officer/admin), `ParkingExportPolicy` (request: building_admin; approve: different building_admin or
parking_officer; download: requester or approver within expiry), `ParkingAnalyticsPolicy` (per-apartment only
if `parking_rules_adopted_at` set).

### C.5 Rules
- **Lookup**: normalize → bidx → `{registered: bool}` only. Quota 5/h, 20/day per user, 200/day per building;
  at 50% building quota alert parking_officer + elders; repeated lookups of the same plate by one user
  (≥3/day) alert officer. Result text includes completeness "Sarakstā pašlaik X% dzīvokļu". Alerts carry counts
  only (A.6 "Alert payloads") — never the plate, bidx or the looking-up user; the officer opens the audit view
  (step-up) to see details.
- **Declarations (no registration oracle)**: `DeclarationWizard` / `ChangeVehicleWizard` / "Pievienot auto" seal the
  plate and insert the vehicle as `pending` **without** touching the partial UNIQUE (which covers `active` only);
  if an active vehicle with the same bidx exists in another apartment, `conflict=true` is set for the officer queue
  ("Numurs jau reģistrēts citā dzīvoklī — pārbaudīt"). The resident always gets the identical "Paldies — gaida
  stāvvietas atbildīgā apstiprinājumu". Each declared plate writes a `plate_lookups` row (`reason_code=declaration`)
  and consumes one unit of the user's lookup quota (the 6th plate in an hour waits for the countdown). Activation
  by the officer re-checks uniqueness inside the transaction.
- **Vehicle removal** (`VehicleRemovalService::remove(Vehicle, RemovalReason)` — the only code path that deletes a
  vehicle: "Dzēst manu numuru", `AccountEraser`, `prune:vehicles`, officer delete): one transaction →
  `SELECT … FOR UPDATE` the permits with that `vehicle_id` → every `active` main permit becomes `revoked` with
  `status_reason` (`vehicle_removed|account_deleted|retention|officer`) and an officer task "Caurlaide Nr. … bez
  auto — jānodod vai jāpiesaista citam auto" is created → then the vehicle row is deleted (FK sets `vehicle_id`
  NULL on the now non-active permits, so the CHECK holds) → audit `vehicle.remove`. The 2-step confirmation
  warns: "Jūsu pamata caurlaide Nr. … kļūs nederīga, līdz piesaistīsiet citu auto" and offers "Mainīt auto" instead.
- **Completeness %** (per building, cached 10 min): `floor(100 × covered / total)` where `total` = apartments of
  the building with `archived_at IS NULL`, and `covered` = those apartments having ≥1 `vehicles` row with
  `status='active'` **or** an `apartment_parking_declarations` row with `has_no_vehicle=true` declared within the
  last 12 months. Pending vehicle changes do not count until officer-verified. Per-stairwell progress rings use
  the same formula per section.
- **Reveal**: reason enum `police_request|blocking_exit|damage|other` + text (required for other), cap 10/day
  per user, audit with `subject_user_ids` = approved members of the apartment, e-mail notice to them (pilot).
- **Sighting**: the client downscales and **converts to JPEG** in a canvas before upload (iOS Safari decodes HEIC
  into the canvas; if the browser cannot decode the file the sheet says "Šo foto formātu nevar pievienot —
  nofotografējiet ar pogu «Fotografēt»"). Server accepts MIME via finfo **jpeg/png/webp only** (HEIC rejected —
  GD cannot decode it), ≤8 MB, ≤40 MP pre-decode check, re-encode JPEG max 1600 px (EXIF/GPS stripped), private disk `building/{id}/sightings/{ulid}.jpg`; no video; Livewire tmp
  uploads private and purged hourly. Repeat flag only if ≥2 distinct reporter apartments in 30 days; auto
  decay after 30 days without sightings.
- **Sighting quotas** (`settings.sighting_quota`, checked before the upload is accepted): per user 10/day and
  3/day for the same plate; per building 100 sightings/day and 50 photos/day; per-building photo storage cap
  500 MB (sum of `photo_bytes` of undeleted photos — over the cap the sheet still accepts the report without a
  photo: "Foto šobrīd nevar pievienot"). Over quota: calm "Šodien ziņojumu limits sasniegts — paldies, ziņojumi
  jau nodoti". Building quota at 50% / 100% → count-only alert to parking_officer + building_admin.
- **Sighting = lookup**: each sighting writes a `plate_lookups` row (`reason_code=sighting`) and consumes one unit of
  the user's lookup quota. The reporter's confirmation is always "Paldies, ziņojums nodots stāvvietas
  atbildīgajam" — `matched_registered`, the repeat flag and status changes other than `dismissed/resolved` are
  never shown to the reporter (so a sighting cannot be used as an unlogged lookup).
- **Exports**: enforcement mode `plate_list|both` enables the police export; fields plate, country, permit
  number, type, valid_to (no apartment unless the official format requires); header: building, controller,
  export number, generated at, generated by role; CSV cells starting `= + - @ \t \r` prefixed with `'`.
  Generated on demand, never stored; metadata row only.
- **Retention jobs**: photos 30 d, sightings 12 mo, lookups 6 mo, vehicles removed 30 d after permit end or
  membership end, annual re-confirmation ("Vai šis auto vēl aktuāls?") with auto-removal after 30 days silence.
  Vehicle deletions in `prune:vehicles` go through `VehicleRemovalService` (reason `retention`) on the
  `pgsql_retention` connection, which therefore also needs UPDATE on `permits` (status, status_reason) and INSERT on
  the officer task table.

### C.6 Screens
Lookup (huge plate input, neutral result, "Ziņot par svešu auto", "Pārbaudīt citu"), Manas caurlaides,
Mainīt auto wizard, Declaration wizard (+ amnesty notice), Sighting sheet, Campaign progress, Legal banner,
"Kas redz šo?" sheet, Filament: permits, vehicles (masked `AB••34`), reveal modal (reason + "Šī darbība tiek
reģistrēta"), analytics tiles (permits per apartment distribution, apartments with 2+ main permits, plates seen
≥N days in 60 without registration, declaration coverage per stairwell), export request/approve, permit
import, sign generator.

### C.7 Acceptance criteria
1. Below L1 no vehicle, permit, declaration, sighting or lookup row can be written by any path (policy, Filament,
   import, console/seeder) and the import page is unavailable; between L1 and L2 only officer/admin can prepare
   data and every resident parking route 404s with nav/tiles hidden; `modules.parking` can be enabled only at L2;
   dropping a condition switches the module off and blocks writes (test + UI explanation of missing conditions).
2. `ab 1234`, `AB-1234`, `АВ1234` (Cyrillic) all match the same registered plate; partial strings never match.
3. Residents only ever receive registered yes/no; no response, payload or log visible to them contains an
   apartment, permit type or history.
4. The 6th lookup in an hour shows a friendly countdown; quotas per day/building enforced; alerts fire.
5. A reveal requires step-up + reason, is capped, logged, and appears in the subject's "Kas skatīja manus datus".
6. Plates are unreadable in a DB dump without env keys; the same plate in two buildings has different bidx; a
   ciphertext copied to another row, table or building fails to open; re-key keeps lookups working and the
   uniqueness constraint intact.
7. Sighting photos lose EXIF/GPS, are only reachable via 5-min signed URLs for officer/admin, and are deleted
   on day 30 (job test).
8. Police export requires two different people; CSV is injection-safe; nothing is stored after download.
9. Guest permits are not plate-bound; plate change creates a replacement permit with history.
10. Overlapping active permits with the same number and type are rejected by the database.
11. Per-apartment analytics are hidden until `parking_rules_adopted_at` is set; residents see per-stairwell aggregates only.
12. "Dzēst manu numuru" removes the vehicle (2-step confirmation) and it stops matching immediately — also when an
    active main permit is bound to it: in the same transaction that permit becomes `revoked`
    (`status_reason=vehicle_removed`) with an officer task; the same holds for AccountEraser and `prune:vehicles`.
13. A permit cannot reference a vehicle of another apartment (DB composite FK).
14. Completeness % matches the C.5 formula on a fixture (e.g. 180 apartments, 100 covered → "55%").
15. HEIC uploads are rejected server-side with the friendly message; client-converted JPEGs pass.
16. Declaring a plate that is active in another apartment returns exactly the same response as any other
    declaration, creates a `pending` vehicle flagged `conflict` for the officer, writes a `plate_lookups` row and
    consumes lookup quota; a reporter's sighting payloads never contain `matched_registered` or the repeat flag, and
    each sighting consumes lookup quota.
17. Sighting quotas (10/day per user, 3/day per plate, 100/day and 50 photos/day per building, 500 MB photo
    storage) are enforced with the calm message; alerts carry counts only.

### C.8 Pest tests required
`Unit/PlateNormalizerTest` (Cyrillic map, separators, lowercase, foreign formats, length bounds — synthetic
plates), `Unit/PlateVaultTest` (round-trip, per-building bidx differs, key version rotation, AAD swap between
rows/tables/buildings fails), `Feature/Parking/RekeyTest` (atomic per building, unique bidx kept, stats rebuilt),
`Feature/Parking/ModuleGateTest` (L0: every write path — policy, Filament create, import page, model guard from
console — refused, lots allowed; L1: officer import/create OK, resident routes 404, nav/tiles hidden, module toggle
refused; L2: module enable OK, resident flows OK; regression L2→L1 and L1→L0 when a condition is cleared: module
auto-off, writes refused, audit entry), `Feature/Parking/CompletenessTest`,
`Feature/Parking/PermitVehicleOwnershipTest` (composite FK), `Feature/Parking/LookupTest` (exact match, payload shape, quotas, alerts,
logging, count-only alert payloads), `Feature/Parking/VehicleRemovalTest` (AC 12: vehicle bound to an active main
permit deleted via MyPermits, AccountEraser and `prune:vehicles` → no CHECK violation, permit `revoked` with reason,
officer task, audit; a raw `DELETE` of such a vehicle outside the service still fails at DB level),
`Feature/Parking/RegistrationOracleTest` (AC 16: byte-identical responses for a conflicting and a fresh plate,
`conflict` flag, lookup row + quota; sighting payloads without `matched_registered`), `Feature/Parking/SightingQuotaTest`
(AC 17), `Feature/Parking/RevealTest` (roles, step-up, reason, cap, subject visibility, e-mail),
`Feature/Parking/PermitConstraintsTest` (EXCLUDE constraint, main needs vehicle, replacement history),
`Feature/Parking/SightingUploadTest` (MIME spoof, HEIC rejected, oversize, decompression bomb, EXIF stripped,
private disk),
`Feature/Parking/SightingRetentionTest`, `Feature/Parking/RepeatFlagTest`, `Feature/Parking/ExportTest`
(four-eyes, CSV injection, signed expiry, no storage), `Feature/Parking/AnalyticsVisibilityTest`,
`Feature/Parking/VehicleRetentionTest`, `Feature/Tenancy/CrossTenantTest` extended with all C models/routes,
`Feature/Filament/ParkingGlobalSearchDisabledTest`, `Browser/PlateLookupSmokeTest`.

---

## D. Explicitly out of MVP
Manager portal (Phase 2), request tracker beyond water/heating kinds, announcements, Web Push, water-loss
helper, meeting/voting tools, document vault, AI anything, SMS, native apps, websockets, series corrections UI,
self-signup, billing, benchmarks, door codes (never), cameras (never), residents' directory (never),
free text visible to other residents (never in MVP).

# Security — Mājas Palīgs

Baseline: OWASP Top 10 (2025) + GDPR. Architecture context: `BRAIN/PLANNING.md` §4–5.

## Keys (SEC-005)

| Key | Purpose | Never |
|---|---|---|
| `APP_KEY` (+ `APP_PREVIOUS_KEYS`) | Laravel encryption (cookies, encrypted casts, TOTP secrets) | used for invites, plates, HMACs |
| `INVITE_KEY` | HMAC of invite codes; HKDF-derived key for e-mail/IP/token HMACs | rotated without re-printing letters |
| `PLATE_ENC_KEY` | XChaCha20-Poly1305 plate ciphertext (Package C) | shared with any other purpose |
| `PLATE_INDEX_KEY` | per-building HKDF → HMAC blind index (Package C) | shared with any other purpose |
| `CONTACT_ENC_KEY` (+ `CONTACT_ENC_KEY_PREVIOUS`) | XChaCha20-Poly1305 roster ciphertext (name, phones, e-mail, comment; AD binds building + table + row + field) and the sealed roster import files (round 2, SEC-011) | shared with the plate keys or any other purpose (ProductionGuard refuses reuse) |
| `CONTACT_INDEX_KEY` | per-building, per-field HKDF → HMAC blind index of roster phones / e-mails | shared with any other purpose |

- Generate: `php artisan majas:keys` (prints) or `--write` (fills empty values in a local `.env` only).
- Production values live only in Coolify environment variables, never in git.
- **Offline escrow:** after generating production keys, the platform admin stores all of them (APP_KEY, INVITE_KEY,
  the two plate keys and the two roster keys) in two
  places offline (sealed envelope at the CoreDigify office safe + an encrypted password-manager vault entry
  with a separate master). Record the date and key version in `BRAIN/MANUAL-TODO.md` (no key material).
- **Restore drill (every 6 months):** restore the latest encrypted DB backup into a scratch Coolify project,
  inject the escrowed keys, run `php artisan tinker --execute="app(App\Audit\AuditChainVerifier::class)->verify(0)"`
  and log in with a test account. Rotating `INVITE_KEY` invalidates all printed codes — only before a new print.
- **Plate keys (Package C):** rotate `PLATE_INDEX_KEY` by replacing it and running `php artisan plates:rekey`
  (all buildings, one transaction each). Rotate `PLATE_ENC_KEY` by setting the new value, putting the old one in
  `PLATE_ENC_KEY_PREVIOUS`, running `php artisan plates:rekey`, verifying a lookup, then removing
  `PLATE_ENC_KEY_PREVIOUS` and escrowing the new key. A failed building aborts alone and alerts the platform admin.
- **Roster keys (round 2, SEC-011):** generate `CONTACT_ENC_KEY` + `CONTACT_INDEX_KEY` (`php artisan majas:keys`) and
  escrow them BEFORE the first roster import — without them nothing can be stored or read. Rotate with
  `php artisan contacts:rekey` (one transaction per building: every field re-sealed with the next key version, blind
  indexes recomputed); for a new encryption master put the old one in `CONTACT_ENC_KEY_PREVIOUS` during the run, then
  remove it and escrow the new key. Losing `CONTACT_ENC_KEY` loses the roster (the house can re-import its sheet).

## Parking data (Package C)
Two-level gate (L1 processing / L2 resident-facing) in policies, model guards and routes; plates only as
ciphertext + per-building blind index; residents get yes/no only; declarations always `pending` (no registration
oracle — `plate_lookups.result` is NULL for declarations, sightings and disputes, so the GDPR export cannot leak it
either); staff lists show NO plate data (vehicle alias from the ULID, sighting group ref from a hash of the blind
index — nothing is decrypted to render a list); reveals need a TOTP step-up + reason, are capped (10/day) and
visible to the data subjects; sighting photos are
re-encoded without EXIF and served by 5-minute signed URLs; police lists need four eyes and are never stored.
Retention: `prune:sightings` (photos 30 d, records 12 mo), `prune:plate-lookups` (6 mo, append-only guard),
`prune:vehicles` (30 d after permit / membership end or an unanswered re-confirmation, through
VehicleRemovalService), `prune:uploads` (Livewire temporary files, hourly).

## Resident roster and CSV imports (round 2, ADMIN-007 / DB-003)
Admin-only contact register of the house (decision D1): gate **L-R** (L-core + DPA scope `roster` + a notice covering
the roster) in the policy `before()`, the model guard (`GuardedByRosterGate`) and the UI. Names, phones, e-mails and
comments only as ContactVault ciphertext + per-building blind indexes; lists show masked values (`+371 2•••••45`,
`j•••@…`); "Rādīt kontaktus" / opening a contact need a fresh SECOND factor, are capped (100 per user per day) and
audited with the linked accounts as data subjects; the masked list itself is audited once per 10 minutes per filter
set; the CSV export needs building_admin + a strong step-up (passkey / TOTP of this session), 5 per building per day,
signed 10 minutes, never stored, formula-safe. Section elders see and edit only their own stairwells (404 for others);
officers, volunteers, residents, the manager and platform admins never reach it; it is excluded from the building
export, manager reports and every resident query. CSV imports (roster now, permits next): the upload itself is refused
below the kind's gate (the Livewire upload URL is not even issued); caps 2 MB / 2000 rows / 30 columns / 1000 chars per
cell, `.csv`/`.txt` + finfo; the file is sealed at once (ImportVault, AD = building + run + kind) and deleted at commit /
discard / 24 h (`prune:imports`); preview masks phones / e-mails; summaries and audit rows carry counts and codes only —
never cell values or file names; `paste` / `csv` / `payload` keys are redacted from logs and GlitchTip; the commit job
re-validates every row inside its savepoint under an advisory lock and reports failures by exception class only.
Retention: `prune:roster` ("Izvācies" + 30 d, contacts of archived apartments), `prune:imports` (24 h / 30 d).
GDPR: a contact is linked to an account only by a VERIFIED e-mail of an APPROVED member of the same apartment; the
self-service export contains the person's roster rows, account erasure deletes them (also below the gate) and tells the
admins a count only.
Permit register (round 2, PARK-011…013): guest permits stay NOT plate-bound — "seen with this guest permit" is a
separate `permit_plate_observations` row (PlateVault AD table `permit_plate_observations`, guest-only trigger, gate
L1 model guard, re-keyed by `plates:rekey`, `prune:permit-observations` after 6 months); register flags ("> 1
caurlaide", "viesu caurlaide vienmēr vienam auto") are SQL over blind indexes, never a decryption. The permit
issuer's CSV runs through the shared import pipeline: upload refused below L1 (D6, even the Livewire signed-upload request),
the file only as ImportVault ciphertext (XChaCha20-Poly1305 under PLATE_ENC_KEY for the kind `permits`, AD
`mp:import:v1|b:|r:|k:`), payload nulled at commit / discard / 24 h (`prune:imports`), preview masked (`AB••34`),
reports / audit / logs carry row numbers and codes only, commit needs a fresh dry-run, the issuer endorsement and an
MFA step-up. The manager list never contains plates; apartment numbers only with `include_apartments` after
`parking_rules_adopted_at` (DB CHECK manager-only + policy + service).

## Tenancy (A01)
Fail-closed row-level tenancy, composite FKs, allow-listed context setters and scope lifts — see
`docs/ADR/0003-row-level-tenancy.md`. Non-members get **404** on every `/b/{slug}` route.

## Database roles (A01/A09)
`database/sql/roles.sql` (superuser, once per cluster): `majas_owner` (schema owner; migrations only),
`majas_app` (DML; SELECT+INSERT on `audit_entries`; SELECT-only on chain heads/anchors; owns nothing),
`majas_retention` (prune commands only). Audit log: append-only, hash-chained per building + platform chain,
BEFORE INSERT trigger (SECURITY DEFINER, advisory lock), UPDATE always rejected, DELETE only by retention after
24 months via a SECURITY INVOKER guard with an anchor written first.

## Headers & CSP (A02, SEC-004)
- Resident app: strict nonce CSP (`script-src 'self' 'nonce-…'`), Livewire CSP-safe Alpine build, no
  `unsafe-eval`. HSTS, `frame-ancestors 'none'`, `X-Frame-Options: DENY`, Permissions-Policy,
  `Referrer-Policy: no-referrer`, `X-App-Version`.
- `style-src` additionally lists **one sha256**: the exact hash of Livewire's own `<style data-livewire-style>` block,
  taken from the served bytes of the same response (`SecurityHeaders::livewireStyleHashes`). wire:navigate parses the
  next page with DOMParser before it swaps nonces, so that block (with the next response's nonce) was reported as a
  `style-src-elem` violation on every navigation (Karen, round 1). No `'unsafe-inline'`, no other inline style is
  allowed; `wire:transition` (unhashable injected styles) is not used (CspLivewireStyleTest, Browser/CspNavigateSmokeTest
  with an enforced policy).
- **Tracked debt:** Filament panels (`/admin/b/…`, `/admin/platform/…`; the `/admin` chooser itself keeps the strict CSP) need `'unsafe-inline' 'unsafe-eval'` (Filament 5
  renders inline scripts without nonce and evaluates Alpine expressions). Panels are behind auth + step-up.
- `no-store` on `/join`, `/login/*`, `/step-up`, downloads, `/admin` and the panels.

## Secrets in URLs and logs (A.6a)
- Invite codes and login tokens travel only in URL **fragments** or POST bodies; legacy `?c=` / `?t=` /
  `/login/confirm/{token}` are answered with 303 and discarded.
- The LV/RU toggle is a full reload. Only at the moment it is pressed, the invite code / link token / half-typed
  e-mail code of `[data-locale-keep]` fields goes to `sessionStorage` (this tab only), is read back once by the
  reloaded page of the same path within 60 s and deleted at once; it never enters a URL or the toggle's POST. The
  "code sent" step itself lives in the session (`App\Auth\PendingCodeStep`: address + time only, no code, lapses
  with the code's TTL).
- nginx `mp_noargs` log format: truncated IP (/24, /48), path without query string, redacted token paths,
  no referer; Docker json-file rotation (10 MB × 5) + Coolify 14-day retention.
- Laravel log tap `App\Logging\RedactSecrets`; Sentry/GlitchTip `App\Support\SentryScrubber`
  (`send_default_pii=false`).

## Authentication (A07)
See `docs/ADR/0005-auth-invite-email-pin.md`: hashed single-use tokens, POST-confirm magic links, rate limits,
identical responses, Argon2id PINs, weak-PIN deny list, MFA/step-up for elevated roles.
Review fixes 0.0.12:
- **MFA for roles:** building_admin, parking_officer and platform_admin step up with TOTP only
  (`StepUpService::requiresMfa`); section elders keep the e-mail code. An account with TOTP never accepts an e-mail
  step-up. TOTP cannot be replaced with the first factor: enrolment is refused while a secret exists, a first
  enrolment needs a fresh step-up, disabling needs a fresh TOTP step-up, enrolment never marks the session
  MFA-fresh; every change is audited (platform chain), mailed to the account and alerted for platform_admin.
- **Session epoch:** PIN set/reset, temp code, TOTP change, role revoke, "Aizmirst ierīces" and erasure bump
  `users.auth_epoch` and rotate the remember token; `EnsureSessionEpoch` (web group + both panels) ends every
  other live session at its next request.
- **E-mail codes:** 10 wrong codes per account per 24 h across tokens (login + step-up share the budget), then
  only the link works; count-only platform alert.
- **PIN DoS:** attempts on a locked handle or in a paused building no longer feed the building limiter; the daily
  lock is progressive (4 h → 24 h → 72 h, an elder reset clears it) with a count-only elder alert. The platform-wide
  `/join` monitor is alert-only and counts well-formed codes only; per-building 60/h (letter waves).

### Passkeys, trusted devices, sessions (0.0.23, `docs/ADR/0006-passkeys-trusted-devices.md`)
- **Second factor = passkey or TOTP** (replaces "TOTP only" above). A passkey login / tap (user verification
  required, exact `APP_URL` origin, counter enforced, single-use 5-min session challenge) is MFA — no TOTP prompt
  after it. E-mail and TOTP stay as fallbacks. Throttles: 120 option requests and 10 failed assertions per 15 min per
  device bucket (/24, /48).
- **Step-up levels:** `any` (e-mail code; elders) < `mfa` (passkey, TOTP or a trusted device; platform panel,
  building_admin, parking_officer) < `strong` (passkey or TOTP of THIS session). `strong` guards break-glass, granting
  a role to someone else (backstop in `BuildingRoleService::grant`), the platform role, adding / removing a factor.
  Sliding 2 h idle / 12 h max as before.
- **Adding a factor** (passkey or TOTP) needs a `strong` step-up when one exists, otherwise a fresh e-mail proof or a
  login < 15 min ago; it is audited and mailed. Removing a passkey / disabling TOTP needs `strong` and bumps the epoch.
- **Trusted device** (`__Host-mp_trusted`, 30 days, opt-in checkbox right after a passkey / TOTP): hash-only row bound
  to the user AND `users.auth_epoch`; never satisfies `strong`; created / used / revoked rows on the platform chain;
  mail on creation; revoke one / all in "Ieeja un drošība"; pruned by `prune:login-tokens`.
- **Sessions:** with the remember cookie the session lives 7 idle days (`SESSION_REMEMBERED_LIFETIME`), without it the
  cookie ends with the browser and the session after 120 idle minutes (`App\Http\Middleware\SessionLifetime`).
- **Lost every device:** `php artisan majas:reset-mfa <email>` (console only, after an out-of-band identity check)
  removes passkeys, TOTP and trusted devices, bumps the epoch, audits and mails; the person signs in by e-mail and
  enrols a new passkey. A platform admin can also run `majas:platform-admin <email>` to get a new TOTP secret.
- **CSP:** the passkey UI is two Alpine components in the Vite bundle (no inline script, no eval); the spatie package's
  routes / Blade / Livewire components are not registered (`dont-discover`).

### Team invitations (0.0.26, ADMIN-006, ROUND2-SPEC §2)
- `role_invitations` (tenant): the invitee's e-mail only as APP_KEY ciphertext (shown to building_admin), an HMAC
  (`Keys::identifier`, label `role-invite:`) for matching and a masked hint; the 32-byte token only as SHA-256. The
  token travels in the URL fragment (`/team-invite/{slug}#t=…`), is passed to Livewire as an action argument (never a
  property), 7 days, single use, max 3 resends (each a new token; the old link and its codes die at once).
- Issue / resend = granting a role to someone else: building_admin + **strong** step-up; revoke: mfa. 20 per building
  per day; never `manager_viewer`; self-invites refused. App role has no DELETE; `prune:role-invitations` deletes rows
  30 days after accepted / revoked / expired.
- Accepting: lookup only inside the bound building (another slug finds nothing), the same neutral text for every
  invalid case, 10 tries / 15 min per device. A 6-digit code (`login_tokens.purpose = team_invite`, bound to the
  invitation, shared 10/24 h wrong-code budget) proves the address; only then is an account created (18+, account
  notice) or the existing one signed in. Never across e-mails (HMAC compare). `BuildingRoleService::grant` skips the
  inviter's strong check only for that open, unexpired invitation (same building, inviter, role, stairwell, token).
- Last-admin guard: the last building_admin cannot be removed from the house panel (policy + service); hand over by
  inviting a new admin first. Audit rows carry ids, role and stairwells — never the e-mail.

### House opening and documents (ROUND2-SPEC §3, GOV-010 / GOV-011 / GOV-012)
- Gate inputs have ONE writer each: controller, mandate, DPIA date and the parking switch only in the opening wizard
  (building_admin + fresh second factor, audited `opening.*`); the DPA date / ref / scope only on the platform
  (`PlatformBuildingContext`, purposes `dpa_record` / `opening_review`). Every change runs CoreGateMonitor,
  ParkingGateMonitor and RosterGateMonitor; the settings page shows the gate inputs read-only.
- Gate L-R (`RosterGate`) = L-core + DPA scope `roster` + a current notice covering `roster`; `GuardedByRosterGate`
  is the model guard for the roster tables (ADMIN-007); a regression is audited `gate.roster.closed` and alerted.
- Document masters (`document_templates`) are platform-only and immutable once published (DB trigger, no delete);
  a house document (`building_documents`) is frozen in the database once it left `draft`. Only editable blocks can
  be written (`DocumentService::saveBlocks` refuses locked keys, also from a forged Livewire call).
- `TemplateLinter` before finalise / publish: locked blocks and text outside markers unchanged, required markers
  present, known placeholders only, no raw HTML, https links only, no phone- or plate-shaped strings, LV + RU filled.
- Rendering (A02/A03): placeholder values are Markdown-escaped, Markdown → HTML with league/commonmark
  `html_input: strip` + `allow_unsafe_links: false`; DPIA answers are HTML-escaped; PDFs via dompdf (DejaVu Sans).
  The public notice page prints only this sanitised HTML. Bodies carry no personal data besides the controller
  name / contact the house typed.
- `RetentionTable` is the single source of the periods shown in the notice and DPA annex B; a test checks every row
  against its prune command.

### First run: platform house page, self-link, view switch (FIRSTRUN-001)
- **Platform house page** `/admin/houses/{slug}` (resident shell, strict CSP, `no-store`): platform_admin only (404 for
  everyone else), fresh second factor. It reads ONE house inside `PlatformBuildingContext` (purpose `house_overview`,
  platform-chain entry per page open): gate status, structure counts, open DPA requests and the platform admin's OWN
  role / membership — no resident data. The `/admin` chooser, `/start` and the platform dashboard ("Sākt šeit") list
  every house through `BuildingIterator` with the same non-personal summary (`App\Platform\PlatformHouses`). A
  platform_admin who opens `/admin/b/{slug}` or `/b/{slug}` of a house where he holds no role is redirected to that page
  instead of a 403 / 404 — he may know every house, so there is no enumeration risk; everybody else keeps the 404.
  The Filament switcher still lists only houses where the person holds a role (ROUND2-SPEC §1.3).
- **"Kļūt par šīs mājas administratoru"**: the platform admin grants building_admin to HIMSELF only, after a strong
  step-up (passkey / TOTP of this session), inside `PlatformBuildingContext` (purpose `role_bootstrap`); audited
  `role.grant` (house chain) + `platform.self_admin` (platform chain); the house's other administrators are told; the
  Komanda page marks the role "Piešķīra sev (platforma)".
- **"Piesaistīt mani dzīvoklim" — documented exception to "never self-approve":** a platform_admin may link HIMSELF to
  one apartment of a house and the membership is APPROVED at once (`approved_by` = the member). Reason: a house's first
  administrator is usually also its only one, and nobody else could approve him (owner's production run, 2026-10-22).
  Limits: platform_admin only, strong step-up, own account only, one active membership per house, gate L-core must be
  open (the ApartmentMembership model guard still applies), purpose `self_link` on the platform chain +
  `membership.self_link` (reason `self_approved`) on the house chain, other approved members of the apartment are
  notified, and Dalības shows "Pašapstiprināts". A building_admin who is NOT platform_admin can request his own
  apartment without a letter (fresh second factor), but it stays **pending** — another administrator or the stairwell
  elder approves it; the approval policy (`ApartmentMembershipPolicy::canDecide`) is unchanged.
- **View switch** "Iedzīvotāja skats ⇄ Admin skats": shown only to someone with a manage role or platform_admin.
  `GET /view/{admin|resident}?b={slug}` remembers the choice in the encrypted, HttpOnly `mp_view` cookie (1 year) and
  redirects only to targets the person may open anyway (`canAccessTenant`, platform house page, `/admin`, `/home`); a
  malformed or unknown slug falls back to the generic entry. The cookie grants nothing.
- **Closed modules** answer 404 as before (`module:` middleware), but a member sees why and who opens it; only the
  house's building_admin gets the link to the fix. At the FIRST L-core opening, heating + outages are switched on
  (both inside the DPA scope and notice already) unless the admin chose one before; audited `opening.first_open`.

## Client IP (A04/A07)
- nginx's realip module is the ONLY place the client IP is resolved; Laravel trusts proxies for
  `X-Forwarded-Proto/Port` only — never for the IP.
- **Production today = Traefik only (no Cloudflare)** → `TRUSTED_PROXY=local` (docker/compose.prod.yaml):
  `X-Forwarded-For` is accepted from the Docker / private ranges and walked right-to-left (`real_ip_recursive on`),
  so `REMOTE_ADDR` = the first untrusted hop = the address Traefik saw. A spoofed `X-Forwarded-For` from a visitor
  only adds entries to the LEFT of the one Traefik appends, so it cannot change the result. Until 0.0.20 the stack ran
  with `TRUSTED_PROXY=cloudflare` without Cloudflare: every visitor was the Traefik container (172.28.0.x) and all
  IP limiters (PIN, e-mail link, code, `/join`, locale) were one shared bucket — a few typos locked everyone out.
- **If Cloudflare is ever put in front (orange cloud):** switch to `TRUSTED_PROXY=cloudflare` (`CF-Connecting-IP`
  from Cloudflare ranges) and allow 80/443 on the server only from Cloudflare ranges (or Authenticated Origin
  Pulls), otherwise a client reaching Traefik directly could send its own `CF-Connecting-IP`.
- Smoke test after every infrastructure change: `curl https://<host>/up/ip` from a known client must print that
  client's own /24 (or /48), never a Cloudflare or 172.x prefix.

## Redis and database transport
Redis holds sessions (auth id, step-up freshness, pending TOTP secret, pending WebAuthn challenges), limiters, the PIN pause and TOTP replay
markers: `REDIS_PASSWORD` is mandatory in `docker/compose.prod.yaml`, the app refuses to boot in production
without it or with `DB_SSLMODE` disable/allow/prefer (`App\Support\ProductionGuard`), sessions are encrypted
(`SESSION_ENCRYPT=true`). Keep Redis on the private Coolify network only.

## GDPR
Split controllership (CoreDigify = platform accounts; the house = house data) and gate **L-core** (controller,
DPA, published building notice) before any membership. The DPA fields (date, ref, scope) are recorded **only in the
platform panel** (platform_admin + TOTP, PlatformBuildingContext, audited) — CoreDigify is the processor and party to
the Art. 28 DPA, so a building admin cannot open L-core or parking L1 on their own say-so. Section elders see only
their own stairwells in the manage panel (memberships, apartments, disputes, rounds, reports). Self-service export (ZIP), deletion = users tombstone,
"Kas skatīja manus datus". House data export without personal data. Retention commands: `prune:login-tokens`
(1 d; also trusted devices 1 d after expiry), `prune:users`, `prune:audit` (24 months), `prune:exports`. Passkeys
(public key material + a device label) and trusted devices (hash + label) are listed in the self-service export and
deleted on erasure.

## Reporting a vulnerability
E-mail the platform admin (CoreDigify). Do not open public issues with exploit details.

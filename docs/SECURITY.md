# Security — Mājas Palīgs

Baseline: OWASP Top 10 (2025) + GDPR. Architecture context: `BRAIN/PLANNING.md` §4–5.

## Keys (SEC-005)

| Key | Purpose | Never |
|---|---|---|
| `APP_KEY` (+ `APP_PREVIOUS_KEYS`) | Laravel encryption (cookies, encrypted casts, TOTP secrets) | used for invites, plates, HMACs |
| `INVITE_KEY` | HMAC of invite codes; HKDF-derived key for e-mail/IP/token HMACs | rotated without re-printing letters |
| `PLATE_ENC_KEY` | XChaCha20-Poly1305 plate ciphertext (Package C) | shared with any other purpose |
| `PLATE_INDEX_KEY` | per-building HKDF → HMAC blind index (Package C) | shared with any other purpose |

- Generate: `php artisan majas:keys` (prints) or `--write` (fills empty values in a local `.env` only).
- Production values live only in Coolify environment variables, never in git.
- **Offline escrow:** after generating production keys, the platform admin stores all four values in two
  places offline (sealed envelope at the CoreDigify office safe + an encrypted password-manager vault entry
  with a separate master). Record the date and key version in `BRAIN/MANUAL-TODO.md` (no key material).
- **Restore drill (every 6 months):** restore the latest encrypted DB backup into a scratch Coolify project,
  inject the escrowed keys, run `php artisan tinker --execute="app(App\Audit\AuditChainVerifier::class)->verify(0)"`
  and log in with a test account. Rotating `INVITE_KEY` invalidates all printed codes — only before a new print.
- **Plate keys (Package C):** rotate `PLATE_INDEX_KEY` by replacing it and running `php artisan plates:rekey`
  (all buildings, one transaction each). Rotate `PLATE_ENC_KEY` by setting the new value, putting the old one in
  `PLATE_ENC_KEY_PREVIOUS`, running `php artisan plates:rekey`, verifying a lookup, then removing
  `PLATE_ENC_KEY_PREVIOUS` and escrowing the new key. A failed building aborts alone and alerts the platform admin.

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

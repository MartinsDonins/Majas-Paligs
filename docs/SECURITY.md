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
- **Tracked debt:** Filament panels (`/platform`, `/manage`) need `'unsafe-inline' 'unsafe-eval'` (Filament 5
  renders inline scripts without nonce and evaluates Alpine expressions). Panels are behind auth + step-up.
- `no-store` on `/join`, `/login/*`, `/step-up`, downloads and panels.

## Secrets in URLs and logs (A.6a)
- Invite codes and login tokens travel only in URL **fragments** or POST bodies; legacy `?c=` / `?t=` /
  `/login/confirm/{token}` are answered with 303 and discarded.
- nginx `mp_noargs` log format: truncated IP (/24, /48), path without query string, redacted token paths,
  no referer; Docker json-file rotation (10 MB × 5) + Coolify 14-day retention.
- Laravel log tap `App\Logging\RedactSecrets`; Sentry/GlitchTip `App\Support\SentryScrubber`
  (`send_default_pii=false`).

## Authentication (A07)
See `docs/ADR/0005-auth-invite-email-pin.md`: hashed single-use tokens, POST-confirm magic links, rate limits,
identical responses, Argon2id PINs, weak-PIN deny list, MFA/step-up for elevated roles.

## GDPR
Split controllership (CoreDigify = platform accounts; the house = house data) and gate **L-core** (controller,
DPA, published building notice) before any membership. Self-service export (ZIP), deletion = users tombstone,
"Kas skatīja manus datus". House data export without personal data. Retention commands: `prune:login-tokens`
(1 d), `prune:users`, `prune:audit` (24 months), `prune:exports`.

## Reporting a vulnerability
E-mail the platform admin (CoreDigify). Do not open public issues with exploit details.

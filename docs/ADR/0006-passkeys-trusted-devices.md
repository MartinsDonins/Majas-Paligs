# ADR 0006 — Passkeys, trusted devices and longer remembered sessions

- Status: accepted (0.0.23, AUTH-006)
- Supersedes: ADR 0005 item 5 ("the platform panel requires TOTP") — TOTP stays, as one of the second factors.

## Context
The owner's first production login (QA 2026-10-20): "admin login is too complicated" and "it throws me out". An
administrator had to type an e-mail code, then a code from an authenticator app, and the step-up came back every 2 h.
Residents and administrators alike lost their session after 120 idle minutes. Building administrators of a
volunteer-run house will not install an authenticator app; a login they avoid protects nothing.

## Decision
1. **Passkeys (WebAuthn) for every account, primary for administrators.** `spatie/laravel-passkeys` (model,
   serializer, `web-auth/webauthn-lib` verification) — but none of its routes, Blade components or Livewire component:
   their inline scripts break the strict nonce CSP and its login controller bypasses `LoginCompleter`. Ceremonies live
   in `App\Auth\Passkeys\PasskeyService`; the browser half is two CSP-safe Alpine components (`passkeyAuth`,
   `passkeyRegister`) on `@simplewebauthn/browser`.
   - User verification REQUIRED (fingerprint, face or the phone's screen lock), resident key required (conditional UI),
     "none" attestation, ES256 / RS256, signature counter enforced (cloned key refused).
   - Exact origin: `APP_URL` scheme + host + port (`StrictCeremonyFactory`), no subdomains — `*.coredigify.com` also
     hosts client sites. RP ID = the `APP_URL` host; moving to an own domain means re-registering passkeys.
   - Options live only in the server session, single-use (pulled on verify), 5-minute TTL, 32-byte challenge.
   - A passkey **login** or **tap** counts as MFA (possession + local user verification, phishing-resistant): no TOTP
     prompt after it. "Ieiet ar pirkstu / Face ID" on `/login` and `/login/pin`, plus conditional UI in the e-mail field.
   - Admins (`StepUpService::requiresMfa`) who signed in by e-mail or PIN are offered a passkey once per login
     (`/me/passkey`, "Vēlāk" continues). Every account can manage passkeys in "Ieeja un drošība" (`/me/security`).
2. **Adding / removing a factor** (passkey or TOTP): an account that already has a second factor needs a passkey / TOTP
   of THIS session; an account without one needs a fresh first-factor proof (e-mail step-up or a login < 15 min ago).
   A registration (UV required) counts as the session's passkey step-up. Removing a passkey bumps the session epoch.
3. **Trusted device ("Uzticēties šai ierīcei 30 dienas").** Offered only right after a real passkey / TOTP of this
   session (10 min). Cookie `__Host-mp_trusted` = `{row id}|{32-byte secret}` (Secure, HttpOnly, SameSite=Lax, Path=/,
   no Domain; Laravel-encrypted); only SHA-256 of the secret is stored. A row counts only while
   `trusted_devices.auth_epoch = users.auth_epoch`, so every credential change, "Aizmirst ierīces", passkey removal,
   role revoke and erasure end all trusted devices at once. Created / adopted (once per step-up window) / revoked rows
   are on the platform audit chain; creation mails the account. List, revoke one, revoke all in "Ieeja un drošība".
   Max 10 live rows per account; expired rows are pruned by `prune:login-tokens` (majas_retention).
4. **Three step-up levels** (`StepUpService`): `any` (e-mail code is enough — section elders), `mfa` (passkey, TOTP or
   a trusted device — platform panel, building_admin, parking_officer) and `strong` (passkey or TOTP of THIS session —
   a trusted device is NOT enough). `strong` guards the dangerous actions: break-glass, granting a role to someone else
   (house roles, the first building_admin, platform role), adding / removing a sign-in factor. One passkey tap
   satisfies it. Key rotation is console-only (`majas:keys`, `plates:rekey`) and therefore out of the web step-up.
5. **Fallbacks stay:** e-mail link / code login, PIN, TOTP step-up; `majas:platform-admin` unchanged (creates a TOTP
   secret when none exists, so it is also the platform admin's recovery). `majas:reset-mfa {email}` (console, audited,
   epoch bump, mail) removes passkeys + TOTP + trusted devices of an account that lost every device; the person then
   signs in by e-mail and creates a new passkey.
6. **Comfortable sessions** (`SessionLifetime`, runs before `StartSession` in the web group and both panels): a device
   carrying the remember cookie (`__Host-mp_remember`, 180 days, rotated on every credential change) keeps its session
   for 7 idle days (`SESSION_REMEMBERED_LIFETIME`); without it the session cookie ends with the browser and the session
   after 120 idle minutes. An expired session's Livewire 419 reloads the page instead of the English dialog.

## Trade-off
- A trusted device turns "something you have" (the phone or laptop, unlocked) into the second factor for 30 days. A
  thief with the unlocked device AND the mailbox could use the panels for up to 30 days — but not break-glass, role
  grants or factor changes, which ask for the passkey / TOTP again. Mitigations: opt-in per device, audit + mail on
  creation, revoke list, epoch binding, never on a shared computer (the checkbox text says so).
- A 7-day remembered session adds no new bearer: the remember cookie on the same device already grants 180 days.
  It removes the CSRF 419s and the repeated step-ups behind "izmet ārā".
- Passkeys synced by Apple / Google accounts inherit that account's security; we accept it (UV is required and the
  credential is origin-bound, unlike a code that can be phished).
- Recovery without a second device needs CoreDigify ops (`majas:reset-mfa` after an out-of-band check) — slower, but
  no self-service path that only needs the mailbox.

## Consequences
- New tables `passkeys` (public material only) and `trusted_devices` (hash only); both in the GDPR export and erased
  with the account. `majas_retention` may DELETE expired trusted devices.
- Tests: `Feature/Auth/PasskeyTest` (software authenticator `Tests\Support\FakeAuthenticator`: real CBOR + ES256),
  `Feature/Auth/TrustedDeviceTest`, `Browser/PasskeySmokeTest` (Chromium virtual authenticator over CDP).

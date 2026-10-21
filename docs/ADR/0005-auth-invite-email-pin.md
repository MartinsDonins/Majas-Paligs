# ADR 0005 — Authentication: invite code + e-mail magic link or handle + PIN

- Status: accepted (implemented in Package A)

## Context
Residents range from teenagers to 80-year-olds without e-mail. Passwords, SMS (cost), Smart-ID and CAPTCHA are
out. A printed letter in each mailbox is the root of trust for "lives in apartment N".

## Decision
1. **Invite code** (10 chars, unambiguous alphabet, `XXXXX-XXXXX`, HMAC-only with `INVITE_KEY`; Cyrillic
   look-alikes mapped, O/0/I/1/L rejected with a hint). QR → `/join#c=CODE` (fragment). Redemption creates a
   **pending** membership after the Art. 13 notice; the stairwell elder approves.
2. **E-mail**: magic link `/login/confirm#t=TOKEN` (GET shows a confirm page, POST logs in) + 6-digit code in
   the same mail; hashed, single-use, 15 min; identical response for unknown e-mails.
3. **No e-mail**: personal handle `apt-XXXX` + 6-digit **PIN** (Argon2id). Blocking limiters per IP (20/h,
   IPv6 /64), per handle (5 → 15 min, 10/day → lock), per building (100/h → alerts + 15-min pause); the global
   monitor only alerts. Identical response and dummy Argon2 verify for unknown handles. Weak PINs refused.
   Elder reset = single-use 24 h temp code that forces a new PIN, audited, members notified.
4. "Remember this phone" via `__Host-mp_remember`; session cookie `__Host-mp_session`; no `XSRF-TOKEN` cookie.
5. Elevated actions require a **step-up** (2 h): TOTP or e-mail code; the platform panel requires TOTP.
   *Superseded by ADR 0006 (0.0.23): the second factor is a passkey or TOTP, optionally a 30-day trusted device.*

## Consequences
- Filament's own login is not used; panels rely on the same session + `RequireStepUp`.
- Tests: `MagicLinkTest`, `LoginCodeTest`, `PinLoginTest`, `StepUpTest`, `SessionCookieTest`, `RedeemInviteTest`.

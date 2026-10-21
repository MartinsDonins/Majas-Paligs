# PRODLIKE: local production-like stack

**Purpose:** reproduce production bugs locally in a real browser. The stack runs the same production image, the
same TLS hop, the same session/cookie and DB/Redis security settings as Coolify (https://kaimini.coredigify.com).
The stack has no live-reload, no Xdebug and no `.env`. Use the dev stack (`scripts/dev.sh`) for development.

## 1. What it mirrors

| Production (Coolify) | Prodlike (`scripts/prodlike/compose.yaml`, project `majas-prodlike`) |
|---|---|
| `ghcr.io/martinsdonins/majas-paligs:main` (CI: `docker build --target prod`) | `majas-paligs:prodlike`, built from **HEAD** with `git archive HEAD \| docker build --target prod -` |
| `docker/compose.prod.yaml`: migrate / web / worker / scheduler | The **same services** via `extends:`, so there is nothing to keep in sync. The only overrides are `pull_policy: never`, `MAJAS_PUBLIC_HOST`, and dropping the Coolify-only `exclude_from_hc` key |
| Traefik terminates TLS → container nginx :8080 | Caddy 2.10 `tls internal` on **https://localhost:8443** → `web:8080`. It sends X-Forwarded-For/-Proto/-Host/-Port/-Server and X-Real-Ip like Traefik. Neither stack has Cloudflare, so neither sends CF-Connecting-IP |
| Postgres 18 with SSL, `DB_SSLMODE=require` | `postgres:18.6-alpine`, `ssl=on` with a self-signed cert. `pg_hba` is **hostssl-only**, so a non-TLS client is rejected |
| DB bootstrap: `database/sql/roles.sql`, NOCREATEDB | The same file, one `majas` database, NOCREATEDB (`scripts/prodlike/pg-bootstrap.sh`) |
| Redis with password | `redis:8-alpine --requirepass` (no volume, so `down` ends all sessions) |
| SMTP | Mailpit catches all mail |
| `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `__Host-mp_session` cookie, encrypted sessions, `TRUSTED_PROXY=local` (0.0.20; was `cloudflare`) | Identical: they come from `compose.prod.yaml` |
| `MAJAS_PUBLIC_HOST` not set (default `kaimini.coredigify.com`) | `localhost:8443`, so invite letters and QR codes point at this stack |

Secrets are generated once into `storage/app/prodlike/prodlike.env`. That file is git-ignored by
`storage/app/.gitignore`. Production keys are never used here, and these keys are never used in production.

## 2. Start / stop (Git Bash, from the repo root)

```sh
scripts/prodlike/up.sh               # build the prod image from HEAD, start everything, seed, create the admin
scripts/prodlike/up.sh --worktree    # same, but build from the working tree (test an uncommitted fix)
scripts/prodlike/up.sh --no-build    # reuse the existing majas-paligs:prodlike image
scripts/prodlike/up.sh --image ghcr.io/martinsdonins/majas-paligs:main   # the exact published image (docker login ghcr.io first)
scripts/prodlike/down.sh             # stop (keeps database, uploads, Caddy CA)
scripts/prodlike/down.sh --wipe      # stop + delete volumes and the saved TOTP secret (prodlike.env is kept)
scripts/prodlike/dc.sh <args>        # docker compose for this stack: ps, logs, exec, restart …
```

`up.sh` is idempotent: run it again after any commit. Each run:

1. Generates the secrets if they are missing.
2. Builds the image.
3. Runs `up -d`. `migrate` runs again, as on every Coolify deploy.
4. Waits for `web` to be healthy and for `https://localhost:8443/up` to return 200.
5. Runs `php artisan db:seed --force` in `web`, as on production. This seeds the series catalogue, privacy and legal notices, and **Pūces iela 51**.
6. Runs `php artisan majas:platform-admin admin@example.test`. The first run saves the TOTP secret to `storage/app/prodlike/admin-totp.txt` and the `otpauth://` URI to `admin-totp.uri`. Later runs pass `--no-totp`, so the secret stays the same.
7. Copies Caddy's root CA to `storage/app/prodlike/caddy-root.crt`.

## 3. URLs and accounts

| What | Where |
|---|---|
| App | https://localhost:8443 (the browser warns about the certificate: accept it, or import `storage/app/prodlike/caddy-root.crt` as a trusted root) |
| Platform panel | https://localhost:8443/platform. Log in as `admin@example.test` with an e-mail code, then a TOTP step-up |
| Mailpit UI | http://127.0.0.1:8036 (the dev stack's Mailpit is on 8026, so the two stacks can run side by side) |
| Health / client IP | https://localhost:8443/up, https://localhost:8443/up/ip |

Always use `localhost`, not `127.0.0.1`. The Caddy site, `APP_URL` and the `__Host-` cookie are all bound to
`https://localhost:8443`.

## 4. Login codes from Mailpit

```sh
scripts/prodlike/mail-code.sh admin@example.test     # → 123456 (newest code mail to that address)
```

Raw API, if you need more than the code:

```sh
curl -s 'http://127.0.0.1:8036/api/v1/search?query=to:"admin@example.test"&limit=1'   # → messages[0].ID
curl -s  http://127.0.0.1:8036/api/v1/message/<ID>                                     # → .Text contains "123 456"
curl -s -X DELETE 'http://127.0.0.1:8036/api/v1/search?query=to:"admin@example.test"'  # clear old mail before a run
```

Any `@example.test` address can log in by e-mail code. A new address becomes a plain account and lands on `/join`.

## 5. TOTP code for the platform admin

```sh
scripts/prodlike/totp.sh        # → 6 digits, computed with the app's own google2fa in the web container
```

The one-liner it runs:

```sh
scripts/prodlike/dc.sh exec -T web php -r 'require "/var/www/html/vendor/autoload.php"; echo (new PragmaRX\Google2FA\Google2FA())->getCurrentOtp($argv[1]), PHP_EOL;' "$(cat storage/app/prodlike/admin-totp.txt)"
```

Node has the same code as `totp()` in `scripts/prodlike/smoke.mjs`. You can also add `admin-totp.uri` to a phone
authenticator app. Each 30-second time step is accepted only once (replay markers live in Redis), so for a second
step-up you must wait for the next code.

## 6. Real browser (Playwright)

Playwright Chromium runs in the dev `browser` image (`majas-paligs-browser:local`; `browser.sh` builds it on first
use). Its container shares the network namespace of the `proxy` container, so **inside the container
`https://localhost:8443` is the same origin a tester uses**. Cookies, `__Host-` and `APP_URL` all match.

```sh
scripts/prodlike/browser.sh                                    # smoke test, screenshots → storage/app/prodlike/screens/
scripts/prodlike/browser.sh node scripts/prodlike/repro.mjs    # your own script (repo is mounted at /var/www/html)
```

In your own scripts:

```js
import { chromium } from 'playwright';
// --ignore-certificate-errors too: without it Chromium will not register /sw.js over the self-signed cert,
// but production does register the service worker.
const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] });
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 390, height: 844 } });
// BASE_URL=https://localhost:8443, MAILPIT_URL=http://mailpit:8025 and PRODLIKE_DIR are set in the container.
```

The smoke test `scripts/prodlike/smoke.mjs` has two kinds of checks:

- **Required** checks make it exit 1 on failure: `/up` returns 200, `/login` shows in LV, `/login` shows in RU after the toggle, and the e-mail code login for `admin@example.test` works.
- **Extended** checks are reported as `WARN`: the toggle returns to the same page, the TOTP step-up reaches `/platform`, RU still works after login, there are no JS console errors, and there are no 5xx responses.

**Rate limits.** Every request reaches the app from the proxy container's IP, exactly as production receives
everything from Traefik's IP. So the IP limiters (`magic-ip` 10 per hour, `login-*`, `livewire` 120 per minute) and the
per-address limit (3 mails per 15 minutes) fill up after a few runs. The UI then shows "Pagaidiet 15 minūtes…".
Without arguments, `browser.sh` runs `php artisan cache:clear` first. That flushes the cache store in Redis DB 1,
which resets the limiters and TOTP replay markers. Sessions are in DB 0 and survive. Run it yourself when needed:
`scripts/prodlike/dc.sh exec web php artisan cache:clear`.

## 7. Logs and debugging

```sh
scripts/prodlike/dc.sh logs -f web            # nginx access (privacy format) + php-fpm + Laravel (LOG_CHANNEL=stderr)
scripts/prodlike/dc.sh logs -f worker         # Horizon jobs (mail is queued)
scripts/prodlike/dc.sh logs --since 5m web worker scheduler
scripts/prodlike/dc.sh exec web php artisan about --only=environment
scripts/prodlike/dc.sh exec pgsql psql -U postgres -d majas
scripts/prodlike/dc.sh exec redis sh -c 'redis-cli -a "$REDIS_PASSWORD" --no-auth-warning keys "*"'
```

`APP_DEBUG=false`, as in production, so error pages are generic and the stack trace appears only in
`docker logs`. Do not flip `APP_DEBUG` to chase a bug. The point of this stack is that it behaves like production.

## 8. Notes

- To test a fix: commit it, then run `up.sh`. Or run `up.sh --worktree` without committing. Then run `browser.sh`.
- Ports: 8443 (proxy) and 127.0.0.1:8036 (Mailpit) on the host. Postgres and Redis are not published.
- The app sends HSTS (`max-age=31536000; includeSubDomains`) for `localhost`. Chromium ignores HSTS for localhost,
  but some other browsers may then force `http://localhost:*` (the dev stack on :8080) to https. If that happens,
  use `http://127.0.0.1:8080` for dev.
- The volume `majas-paligs_node_modules_browser` is shared with the dev `browser` service. `down.sh --wipe` never deletes it.
- First run (2026-10-20, image 0.0.18 = production): the smoke test reproduced these issues, which are also present on
  production (all fixed in 0.0.20–0.0.22, BRAIN/TASK.md FIX-001…FIX-007; re-check with `scripts/prodlike/browser.sh node scripts/prodlike/qa-verify.mjs`):
  1. The LV/RU toggle redirects to `url()->previous()`, which is `/sw.js` after the service worker was fetched, so the
     user lands on the worker's JavaScript ("RU does not work").
  2. `/up/ip` shows `172.28.0.0/24` both here and on https://kaimini.coredigify.com/up/ip. `TRUSTED_PROXY=cloudflare`
     but there is no Cloudflare, so all visitors share one IP bucket in every IP limiter.
  3. The Filament favicon is `http://…/icons/icon.svg` (`asset()` is called at panel registration, before the
     forwarded scheme is applied), so CSP blocks it. The default `ui-avatars.com` avatars are blocked by CSP too.
- **Passkeys (0.0.23, ADR 0006):** `scripts/prodlike/browser.sh node scripts/prodlike/passkey-verify.mjs` drives a
  Chromium virtual authenticator (CDP `WebAuthn.*`): offer after the admin's e-mail login → passkey + trusted device →
  passkey login → e-mail login on the trusted device without a code → break-glass still asks for a tap. It expects
  the admin WITHOUT a passkey; before a re-run: `scripts/prodlike/dc.sh exec -T web php artisan tinker
  --execute="App\Models\Passkey::query()->delete(); App\Models\TrustedDevice::query()->delete();"`. Once the admin has
  a passkey, an e-mail login no longer shows the `/me/passkey` offer.
- **TLS-scanning antivirus on the dev machine** (seen 2026-10-21: Avast "Web/Mail Shield" re-signs registry.npmjs.org):
  `npm ci` in the image build or in the `browser` container fails with `UNABLE_TO_VERIFY_LEAF_SIGNATURE`. Export the
  antivirus root (Windows: `Cert:\LocalMachine\Root`) to a PEM OUTSIDE the repo and pass it in without changing the
  Dockerfile: `NODE_EXTRA_CA_CERTS=<pem>` for the container, or a copy of the Dockerfile whose `npm ci` /
  `composer install` lines use `RUN --mount=type=secret,id=ca,…` with `docker build --secret id=ca,src=<pem> -f <copy>`.
  Never put the PEM under the repo: `up.sh --worktree` sends the whole working tree (including `storage/app`) as the
  build context.
- Never load data from `DOC/` into this stack. It holds residents' personal data. Use only synthetic `@example.test` users.

#!/usr/bin/env bash
# Run Node/Playwright inside the stack (Chromium from the dev `browser` image, sharing the proxy's network namespace,
# so https://localhost:8443 inside == the tester's URL). Default: the smoke test scripts/prodlike/smoke.mjs.
#   scripts/prodlike/browser.sh                                  smoke test (screenshots → storage/app/prodlike/screens)
#   scripts/prodlike/browser.sh node scripts/prodlike/my-repro.mjs
set -euo pipefail
export MSYS_NO_PATHCONV=1
here="$(dirname "$0")"
if ! docker image inspect majas-paligs-browser:local >/dev/null 2>&1; then
  echo "Building the dev browser image (once)…"
  (cd "$here/../.." && docker compose --profile browser build browser)
fi
cmd="${*:-node scripts/prodlike/smoke.mjs}"
if [ $# -eq 0 ]; then
  # Every request reaches the app from the proxy's IP (exactly like production behind Traefik), so the IP rate
  # limiters and the 3-mails-per-15-min limit for admin@example.test fill up after a few runs. The smoke test resets
  # them (cache store = Redis DB 1; sessions in DB 0 survive). Own scripts: run the same command when needed.
  echo "Resetting rate limiters (php artisan cache:clear) before the smoke test…"
  "$here/dc.sh" exec -T web php artisan cache:clear --no-interaction >/dev/null
fi
# The dev stack's Linux node_modules volume is external to this project: make sure it exists.
docker volume create majas-paligs_node_modules_browser >/dev/null
# --no-deps: the stack is already up (up.sh); never re-run migrate just to open a browser.
exec "$here/dc.sh" --profile browser run --rm --no-deps browser \
  "npm ls playwright >/dev/null 2>&1 || npm ci --no-audit --no-fund >/dev/null; $cmd"

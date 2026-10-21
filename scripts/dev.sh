#!/usr/bin/env bash
# Mājas Palīgs dev wrapper (MVP-SPEC A.1). Hides `docker compose exec` and Git Bash path conversion.
# Usage: scripts/dev.sh up|down|init|artisan …|composer …|npm …|pest …|browser …|lighthouse|pint …|stan …|psql|sh
set -euo pipefail
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/.."

if [ -z "${USER_ID:-}" ]; then export USER_ID="$(id -u 2>/dev/null || echo 1000)"; fi
if [ -z "${GROUP_ID:-}" ]; then export GROUP_ID="$(id -g 2>/dev/null || echo 1000)"; fi
# Git Bash on Windows reports large synthetic ids; containers use 1000 there.
if [ "${USER_ID}" -gt 60000 ] 2>/dev/null; then export USER_ID=1000 GROUP_ID=1000; fi

dc() { docker compose "$@"; }
run_app() { dc run --rm --no-deps app "$@"; }
exec_app() {
  if [ -n "$(dc ps -q app 2>/dev/null)" ]; then dc exec app "$@"; else run_app "$@"; fi
}

cmd="${1:-help}"; shift || true
case "$cmd" in
  up)       dc up -d --build "$@" ;;
  down)     dc down "$@" ;;
  init)
    [ -f .env ] || cp .env.example .env
    dc up -d pgsql redis mailpit
    dc run --rm --no-deps --user root app sh -c 'chown -R ${USER_ID:-1000}:${GROUP_ID:-1000} /var/www/html/vendor' || true
    exec_app composer install
    dc run --rm vite sh -c 'npm ci'
    exec_app php artisan key:generate
    exec_app php artisan majas:keys --write
    exec_app php artisan migrate --database=pgsql_owner --seed --force
    ;;
  artisan)  exec_app php artisan "$@" ;;
  migrate)  exec_app php artisan migrate --database=pgsql_owner "$@" ;;
  composer) exec_app composer "$@" ;;
  npm)      dc run --rm vite npm "$@" ;;
  pest)     exec_app sh -c 'php artisan config:clear >/dev/null && vendor/bin/pest "$@"' -- "$@" ;;
  pint)     exec_app vendor/bin/pint "$@" ;;
  stan)     exec_app vendor/bin/phpstan analyse --memory-limit=1G "$@" ;;
  # Real-browser tests (tests/Browser) in the `browser` image: Playwright Chromium + fresh Vite build.
  browser)
    dc --profile browser run --rm browser sh -c 'npm ls >/dev/null 2>&1 || npm ci --no-audit --no-fund; npm run build >/dev/null && php artisan config:clear >/dev/null && vendor/bin/pest --testsuite=Browser "$@"' -- "$@"
    ;;
  # Lighthouse mobile accessibility (AC 16) against the LOCAL demo build of Pūces iela 51 (DemoSeeder data).
  lighthouse)
    dc --profile browser up -d browser
    status=0
    dc exec browser sh -c 'npm ls >/dev/null 2>&1 || npm ci --no-audit --no-fund; npm run build >/dev/null       && php artisan migrate --database=pgsql_owner --force       && php artisan db:seed --class=DemoSeeder --database=pgsql_owner --force       && php artisan optimize:clear >/dev/null && node scripts/lighthouse/audit.mjs' || status=$?
    dc --profile browser stop browser
    exit $status
    ;;
  psql)     dc exec pgsql psql -U postgres -d "${1:-majas}" ;;
  sh)       exec_app sh ;;
  *)
    echo "Usage: scripts/dev.sh up|down|init|artisan|migrate|composer|npm|pest|browser|lighthouse|pint|stan|psql|sh"
    ;;
esac

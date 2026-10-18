# Mājas Palīgs

A neutral "house operating layer" for residents of Latvian apartment buildings (LV + RU): riser-aware heating
and outage reports, a parking permit registry and calm, structured information — instead of chat noise.
Pilot: Pūces iela 51, Rīga (602 series, 5 stairwells, 180 apartments). Working host: `kaimini.coredigify.com`.

- Plan & context: `BRAIN/PLANNING.md`, tasks: `BRAIN/TASK.md`, specs: `BRAIN/docs/`.
- Architecture decisions: `docs/ADR/`, security: `docs/SECURITY.md`.

## Stack
Laravel 13 (PHP 8.4) · Livewire 4 + Alpine (CSP-safe) · Filament 5 · Tailwind 4 · PostgreSQL 18 · Redis 8 ·
Horizon · Pest 5 · Larastan · Docker (serversideup/php) · Coolify.

## Local development (Docker only — no local PHP needed)

```bash
cp .env.example .env
scripts/dev.sh init          # pgsql/redis/mailpit, composer + npm install, keys, migrate + seed (structure only)
scripts/dev.sh artisan db:seed --class=DemoSeeder   # optional, LOCAL ONLY synthetic accounts + invite codes
scripts/dev.sh up            # app on http://localhost:8080, Mailpit on http://localhost:8025
docker compose --profile assets up -d vite   # Vite dev server (HMR) on :5173
```

Windows: keep the working copy on WSL2 ext4 for speed, or keep `VITE_POLLING=1` when it lives on `C:\`.

| Task | Command |
|---|---|
| Tests (PostgreSQL `majas_test`) | `scripts/dev.sh pest` |
| Real-browser tests (Chromium, Mailpit; `tests/Browser`) | `scripts/dev.sh browser` — screenshots in `tests/Browser/Screenshots/` |
| Lighthouse mobile a11y (AC 16, demo build) | `scripts/dev.sh lighthouse` — reports in `storage/app/lighthouse/` |
| Code style | `scripts/dev.sh pint` |
| Static analysis (level 6) | `scripts/dev.sh stan` |
| Migrations (as `majas_owner`) | `scripts/dev.sh migrate` |
| Platform admin + TOTP secret | `scripts/dev.sh artisan majas:platform-admin you@example.test` |
| Fresh-cluster bootstrap check | `docker compose run --rm -e PGHOST=pgsql -e PGUSER=postgres -e PGPASSWORD=postgres-dev-only app sh scripts/bootstrap-check.sh` |

## Data protection
`DOC/` holds raw source material with personal data and is git-ignored — never copy it into code, seeds,
tests or commits. Seeds contain building structure only; tests use synthetic `@example.test` data.

## Version
`VERSION` is the single source of truth (footer, `/up` JSON, `X-App-Version` header). Every commit bumps the
patch version.

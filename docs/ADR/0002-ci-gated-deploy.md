# ADR 0002 — CI-gated deploy to Coolify

- Status: accepted

## Context
The global rule is "auto-deploy on push to main". A broken main would ship to residents immediately.

## Decision
- GitHub Actions (`.github/workflows/ci.yml`) runs on every push/PR: personal-data grep guard, VERSION check
  (PRs), roles bootstrap with the production `database/sql/roles.sql`, Pint, Larastan level 6, Pest on
  PostgreSQL 18, fresh-cluster bootstrap check (`scripts/bootstrap-check.sh`), `composer audit`, Vite build,
  `npm audit`, lang parity, `docker build --target prod`.
- Only a **green main** calls the Coolify deploy webhook (`COOLIFY_WEBHOOK`, `COOLIFY_TOKEN` secrets).
- Coolify runs `docker/compose.prod.yaml`: one-shot `migrate` (majas_owner) → `web` / `worker` / `scheduler`
  (majas_app; retention credentials only in `scheduler`).

## Consequences
- Still "auto-deploy from main", but a red main never deploys.
- If Coolify cannot run one-shot services, CI runs the same migrate command over a private tunnel before the
  webhook; `web` never receives the owner credentials.

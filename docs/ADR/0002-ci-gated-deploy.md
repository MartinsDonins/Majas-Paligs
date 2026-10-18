# ADR 0002 — CI-gated deploy to Coolify

- Status: accepted (revised 2026-10-18, 0.0.12: image delivery model chosen)

## Context
The global rule is "auto-deploy on push to main". A broken main would ship to residents immediately.
The first version of this ADR did not say how the image reaches Coolify: `compose.prod.yaml` pulled
`ghcr.io/coredigify/majas-paligs:latest`, but CI only built the image locally and pushed nothing, and the repository
owner is `MartinsDonins`, not `coredigify`. Coolify would have pulled a tag that did not exist.

## Decision
- GitHub Actions (`.github/workflows/ci.yml`) runs on every push/PR: personal-data guard (self-test + scan,
  `scripts/personal-data-guard.sh`), VERSION check (PRs), roles bootstrap with the production
  `database/sql/roles.sql`, Pint, Larastan level 6, Pest on PostgreSQL 18, fresh-cluster bootstrap check
  (`scripts/bootstrap-check.sh`), `composer audit`, Vite build, `npm audit`, lang parity, `docker build --target prod`.
- **Image model (a) — CI builds and pushes, Coolify pulls.** Only after the `test` job is green on `main`, the
  `image` job builds the `prod` target once and pushes it to GHCR with `docker/login-action` (`GITHUB_TOKEN`,
  `packages: write`) as:
  - `ghcr.io/martinsdonins/majas-paligs:<VERSION>` and `:sha-<12-char commit>` — immutable, for rollback;
  - `ghcr.io/martinsdonins/majas-paligs:main` — the moving tag Coolify deploys.
- The `deploy` job (needs `image`) calls the Coolify deploy webhook (`COOLIFY_WEBHOOK`, `COOLIFY_TOKEN` secrets).
  `docker/compose.prod.yaml` uses `image: ${MAJAS_IMAGE:-ghcr.io/martinsdonins/majas-paligs:main}` with
  `pull_policy: always`, so the image that passed the tests is the image that runs.
- **Rollback:** set `MAJAS_IMAGE` in Coolify to an immutable tag (`…:0.0.11` or `…:sha-<commit>`) and redeploy.
- `concurrency: cancel-in-progress` per ref means an older run never moves `:main` after a newer push.
- Coolify runs `docker/compose.prod.yaml`: one-shot `migrate` (majas_owner) → `web` / `worker` / `scheduler`
  (majas_app; retention credentials only in `scheduler`).

Rejected: (b) `build:` in compose so Coolify builds from git — the webhook deploys the branch head, which can be a
commit newer than the one CI tested, and the production build would run on the app server.

## Consequences
- Still "auto-deploy from main", but a red main never deploys and nothing untested is built for production.
- The GHCR package is private by default: the Coolify server needs registry credentials (a read-only PAT with
  `read:packages`) — human task in `BRAIN/MANUAL-TODO.md`.
- **Not yet exercised:** as of 0.0.12 local `main` has not been pushed, so no CI run exists on GitHub. The claim
  "a red main never ships" holds only after the first green run (DEPLOY-001.5 stays open until then).
- If Coolify cannot run one-shot services, CI runs the same migrate command over a private tunnel before the
  webhook; `web` never receives the owner credentials.

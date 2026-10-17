# ADR 0001 — Laravel monolith at the repository root

- Status: accepted (owner confirmed Laravel on 2026-10-17)
- Deciders: product owner, lead architect

## Context
The global CoreDigify baseline prescribes TypeScript with `backend/` + `frontend/web` + `frontend/mobile`.
Mājas Palīgs is maintained by one developer, needs server-rendered pages that work on low-end phones,
a full admin panel, strict row-level tenancy, PDF generation and PostgreSQL-specific features (triggers,
composite FKs, exclusion constraints) from day one.

## Decision
- One **Laravel 13** application (PHP 8.4) at the **repository root**: Livewire 4 + Alpine (resident PWA),
  Filament 5 (`platform` + `manage` panels), Tailwind 4, PostgreSQL 18, Redis 8, Horizon.
- `frontend/mobile/` stays reserved and empty (no native apps in the MVP — the PWA covers it).
- `BRAIN/` (AI context) and `DOC/` (git-ignored source material) stay where they are.

## Consequences
- Deviation from the TypeScript baseline is intentional and documented here.
- One deployable image, one test suite (Pest 5 against real PostgreSQL), one place for policies.
- The global rules that still apply: named exports do not exist in PHP; async/await rule maps to queued jobs;
  error format `{code, message, metadata}` applies to any future JSON API.

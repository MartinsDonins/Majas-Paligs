# ADR 0003 — Row-level, fail-closed multi-tenancy

- Status: accepted (implemented in Package A)

## Context
The product is sold per building. Residents of one building must never see another building's rows; some
legitimate flows are cross-building (a user with flats in two houses, `/join`, GDPR export, scheduler jobs).

## Decision
- Single database; every tenant table has `building_id NOT NULL`, an index led by `building_id`,
  `UNIQUE(building_id, id)` and **composite FKs** `(building_id, parent_id) → parent(building_id, id)`.
- `App\Tenancy\CurrentBuilding` (per unit of work) + `BuildingScope` global scope that **throws
  `MissingBuildingContext`** without a context; `BelongsToBuilding` auto-fills and guards writes.
- `set()` cannot re-point a context (`BuildingContextAlreadySet`); `runFor()` restores the previous one.
  A `set()` context ends with its request (`CurrentBuilding::endRequest()` on kernel terminate).
- Allow-listed context setters (Arch test): `SetCurrentBuilding`, `InviteResolver`,
  `UserScopedQuery::eachBuilding`, `BuildingIterator`, `RestoreBuildingContext`, `BreakGlass`,
  `PlatformBuildingContext`. Sanctioned scope lifts: `InviteResolver`, `UserScopedQuery`, `BuildingIterator`,
  `BreakGlass`.
- Resident routes: `auth → SubstituteBindings → SetCurrentBuilding → member (404) → role/step-up`, the same
  middleware registered as persistent Livewire middleware. Filament `manage` uses native tenancy:
  `IdentifyTenant` (via `User::getTenants/canAccessTenant` → `UserScopedQuery`) runs before
  `SetCurrentBuilding` (priority list in `bootstrap/app.php`).
- `Building` is not a tenant model (explicit route binding by slug).

## Consequences
- Cross-building leaks need a bug in two places (scope + FK). Tests: `CrossTenantTest`,
  `CompositeForeignKeyTest`, `MiddlewareOrderTest`, `FilamentTenancyTest`, `Arch/TenancyArchTest`.
- PostgreSQL RLS stays a Phase 3 defence-in-depth item.

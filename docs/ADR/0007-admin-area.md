# ADR 0007 — One administration area under /admin

- Status: accepted (0.0.25, ADMIN-005)
- Refines: MVP-SPEC A.8 (panel paths). Spec: `BRAIN/docs/ROUND2-SPEC.md` §1 (decision D3).

## Context
After the first production login (2026-10-21) the owner asked for one clearly separated administration area. There
were two unrelated Filament panels — `/manage/{building}` for house administration and `/platform` for CoreDigify —
and nothing in the resident app pointed to the platform panel at all.

## Decision
1. **Entry `/admin`** (`Admin\AdminEntryController`, resident-shell Blade view, strict nonce CSP). Targets are the
   buildings where the person holds a manage role (building_admin, section_elder, parking_officer) plus the platform
   for a platform_admin: none → `/home` with "Jums nav administrēšanas lomas", exactly one → straight there, otherwise
   a chooser card per target. The L-core summary on each card needs that building's context, so the controller is an
   allow-listed `UserScopedQuery` caller (`eachBuilding`) — no new context setter, no new scope lift.
2. **Paths, not ids, move.** The building panel is `/admin/b/{slug}` (panel id `manage`), the platform panel
   `/admin/platform` (panel id `platform`). Route names (`filament.manage.*`, `filament.platform.*`), policies,
   tenancy and step-up levels are unchanged. `/admin/b` (not `/admin/{slug}`) keeps a building slug from ever colliding
   with `platform` and keeps `/admin` itself out of Filament.
3. **Old URLs keep working.** `/manage[/…]` and `/platform[/…]` answer 301 (GET/HEAD, query kept) or 308 (other
   methods) to the same slug under `/admin` (`LegacyPanelRedirect`, session-less, no control characters echoed).
4. **Links both ways.** The brand link of both panels goes to `/admin`; both user menus have "Uz mājas lietotni";
   the building panel's tenant menu has "Visas mājas" (+ "Platforma" for a platform_admin); the platform user menu has
   "Mājas administrēšana"; the resident "Vairāk" page has "Administrēšana" for anyone with a manage role anywhere.
5. **Bare `/admin/b`** for someone without a building role (a platform_admin) returns to `/admin` instead of a 403
   (`AdminPanelEntry`, a subclass of Filament's Authenticate — a middleware "before" Authenticate would be re-sorted
   after it by the middleware priority list).
6. **CSP:** the Filament exception (`'unsafe-inline' 'unsafe-eval'`, SEC-004.1 debt) now matches `admin/b*` and
   `admin/platform*` only; `/admin` is a normal resident page.

## Consequences
- Bookmarks, mails and printed instructions with `/manage/…` still land on the right page.
- Navigation groups follow ROUND2-SPEC §1.2 (Cilvēki, Māja, Siltums un ūdens, Stāvvieta, Pārvaldība), registered as
  `NavigationGroup` objects with translated label closures so the order also holds in Russian.

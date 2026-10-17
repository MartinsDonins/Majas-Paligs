# TASK — Mājas Palīgs

Format: `[ICON] TYPE-NR: Latvian title` — description in English.
Custom type added: **SERIES** — building series catalogue & generator.
Custom type added: **HEAT** — heating map / riser reporting.
Custom type added: **PARK** — parking registry.

## Fāze 0 — Pamats
- ✅ DOC-001: Projekta plāna izveide - COMPLETED — BRAIN/PLANNING.md from chat analysis.
- ✅ SEC-001: Personas datu izslēgšana no git - COMPLETED — `DOC/` and `*.zip` in .gitignore.
- 🆕 DEPLOY-001: Laravel projekta skelets — Laravel + Livewire + Filament + PostgreSQL + Redis, Docker, Coolify app.
- 🆕 AUTH-001: Pieslēgšanās ar e-pasta saiti — magic link login, rate limited.
- 🆕 AUTH-002: Dzīvokļa uzaicinājuma kodi — per-apartment invite codes, printable PDF sheet, elder approval.
- 🆕 DB-001: Mājas, kāpņu telpas, dzīvokļi, lomas — core tables + building_id tenancy scope + cross-tenant tests.
- 🆕 SERIES-001: Sēriju katalogs — series, section layouts, apartment types, rooms, risers (JSONB templates).
- 🆕 SERIES-002: Mājas ģenerators no sērijas — generate sections/apartments/radiators/risers; overrides.
- 🆕 SERIES-003: 602. sērijas sagatave (Puces 51) — seed template; 4 apts/floor, +4 per floor vertically.
- 🆕 UI-001: LV/RU valodas — i18n, language switcher, default LV.
- 🆕 SEC-002: GDPR pamati — privacy notice, consent, data export/delete, audit_log.

## Fāze 1 — MVP
- 🎯 HEAT-001: Siltuma karte (režģis pa stāviem) — floors × positions grid per stairwell, colour by riser aggregate.
- 🎯 HEAT-002: Radiatoru ziņojumi — per room/radiator status warm/lukewarm/cold/overheated, optional °C.
- 🆕 HEAT-003: Apsekojuma kārtas — survey rounds for comparable data.
- 🆕 HEAT-004: Atslēgumu ziņošana — "no cold/hot water now" on same grid, live riser view.
- 🆕 HEAT-005: Pārskats pārvaldniekam — PDF/CSV export per riser.
- 🎯 PARK-001: Caurlaižu un auto reģistrs — lots, permits (main/guest), vehicles; plates encrypted + blind index.
- 🎯 PARK-002: Auto numura pārbaude — resident sees yes/no only; elder sees apartment; audit logged.
- 🆕 PARK-003: Svešu auto ziņojumi — sightings with photo, repeat flagging, 30-day photo retention.
- 🆕 PARK-004: Stāvvietas analītika — permits per apt, guest-as-permanent, plates without permit.
- 🆕 PARK-005: Saraksts policijai — export resident plate list.

## Fāze 2+ — skat. PLANNING.md §6

# ROUND2-SPEC — Administrēšana, mājas atvēršana, iedzīvotāju saraksts, caurlaižu reģistrs

> Round 2 of Mājas Palīgs / Kaimiņos after the first production login (kaimini.coredigify.com, 2026-10-20/21).
> Status: **authoritative build spec**, revision 1 (2026-10-21, lead architect). Awaiting the Karen round-2 gate.
> Builds on `BRAIN/PLANNING.md` (rev. 4 + 0.0.20–0.0.23 notes), `BRAIN/docs/MVP-SPEC.md` (conventions §0 apply to
> everything below), `BRAIN/docs/DESIGN-SYSTEM.md`, `docs/SECURITY.md`, ADR 0001–0006.
> No application code in this document; names are the contract the code must follow.
> Personal data rule: nothing from `DOC/` and nothing from the owner's Google Sheet is ever fetched, quoted, seeded or
> used in tests. The owner uploads his own CSV export through the import pipeline (§4). Examples here are synthetic.

## 0. Why this round

Owner requests (2026-10-21, after production login and the QA fixes FIX-001…FIX-007 / AUTH-006):

| # | Request | Root cause seen in production |
|---|---|---|
| R1 | One clearly separated **admin area** at `/admin`; platform items only for platform_admin; per-building admin via a switcher; a **"Komanda"** page to invite people by e-mail and hand administration over. | Two unrelated panels (`/manage/{b}`, `/platform`), no way to add an admin who is not yet an approved member, roles granted only from a dropdown of approved members. |
| R2 | A **house opening wizard** that explains *why* each gate exists and lets an authorised admin satisfy L-core and parking L1/L2 step by step, with editable DPA / privacy notice / DPIA-lite templates (LV + RU). | The owner could not see parking at all: gates closed, nav hidden, nothing told him what was missing. |
| R3 | **Resident roster** per apartment (names, phones, e-mail, owner/tenant/contact, BIS yes/no, WhatsApp yes/no, comment), encrypted, admin + stairwell elder only, audited, GDPR-aware, with CSV import (mapping → preview → dry-run → import). | Today the house runs on a half-filled Google Sheet. |
| R4 | **Parking permit register** with filters ("> 1 permit", "guest permit always same car"), CSV import like the roster, CSV export for police / manager; import blocked with an explanation below gate L1. | PermitImportPage is paste-only, gives no preview, and is invisible below L1. |

### 0.1 Decisions that change earlier rules (explicit, for the record)

| Id | Decision | Supersedes / refines |
|---|---|---|
| **D1** | The roster is an **admin-only contact register of the house** (house = controller, CoreDigify = processor). It is never shown to residents, never searchable by residents, never exported to the manager. | PLANNING §5 "no names/phones collected in MVP" — superseded **for the roster only**, by owner request 2026-10-21. PLANNING §5 "Never build: residents' directory" **still holds**: no resident-facing directory. |
| **D2** | New DPA scope and notice module **`roster`**, and a new gate **RosterGate (L-R)** = L-core + `dpa_scope ∋ roster` + current notice covering `roster`. | MVP-SPEC A.4 enum lists `core|heating|outages|parking` → add `roster`. |
| **D3** | Panels move under `/admin`: building panel `/admin/b/{slug}/…`, platform panel `/admin/platform/…`, entry `/admin`. **Panel ids stay `manage` and `platform`** (route names `filament.manage.*`, tests, policies unchanged). Old URLs 301. | MVP-SPEC A.8 paths. |
| **D4** | Building roles may be **offered by e-mail invitation** to a person who is not (yet) an apartment member. Accepting creates the account if needed. Resident-app elder tools (`/b/{slug}/help`) still require an approved membership (`member` middleware unchanged, 404). | BuildingRoleResource "candidates = approved members only" (stays as the quick path). |
| **D5** | Team invitations work **below L-core** (the house cannot be opened without a team). Data involved = the invitee's e-mail (platform account data, CoreDigify controller) + the role (house mandate). | — (clarifies PLANNING §5 controllership) |
| **D6** | Import **upload itself** is refused below the relevant gate (roster: L-R, permits: L1) — a staged CSV is already personal data in our storage. | PermitImportPage checked only the commit. |
| **D7** | Templates (DPA, privacy notice, DPIA-lite) are **platform-versioned masters**; a building gets an **editable copy** whose required sections are locked by markers and checked by a linter before it can be finalised / published. | Building notice = template + controller placeholders only (no per-building edit). |
| **D8** | Guest permits stay **not plate-bound**. "Guest permit always same car" is detected from **observations** (`permit_plate_observations`, sealed like plates), never by binding a vehicle to a guest permit. | PLANNING §4.7 (unchanged rule, new evidence table). |

### 0.2 Non-goals (round 2)
No resident-facing roster, no SMS/WhatsApp API sending, no fetching of Google Sheets / URLs, no XLSX parsing (CSV only;
Excel/Sheets "Save as CSV"), no document vault / signed-PDF upload (references only), no name search over encrypted
names, no e-signature, no manager portal (MGR-001 stays Phase 2), no change to L-core / L1 / L2 conditions themselves.

---

## 1. Admin area `/admin` (ADMIN-005)

### 1.1 URL map
| URL | What | Access |
|---|---|---|
| `GET /admin` | **Entry** (`App\Http\Controllers\Admin\AdminEntryController`, resident-shell Blade view `admin.entry`) | `auth`. 0 targets → 302 `/home` with flash "Jums nav administrēšanas lomas". Exactly 1 target → 302 to it. Otherwise a chooser: one card per building with a manage role (name, address, the user's roles as chips, gate summary chip), plus a "Platforma" card for platform_admin. |
| `/admin/b/{building:slug}/…` | Filament panel id **`manage`** (path `admin/b`, tenant slug) — per-building administration | `User::canAccessPanel('manage')` + `canAccessTenant` (unchanged, `UserScopedQuery::manageBuildingIds()`), `RequireStepUp` |
| `/admin/platform/…` | Filament panel id **`platform`** (path `admin/platform`) | platform_admin + `RequireStepUp:mfa` (unchanged) |
| `GET /team-invite/{building:slug}` | Accept a team invitation (fragment `#t=TOKEN`) — §2.3 | guest or auth; `tenant` middleware (context from the bound slug, as `/privacy/{building:slug}`) |
| `GET /manage`, `/manage/{rest}` | **301** → `/admin`, `/admin/b/{rest}` (query string kept) | — |
| `GET /platform`, `/platform/{rest}` | **301** → `/admin/platform`, `/admin/platform/{rest}` (query string kept) | — |

Rules:
- The building panel path is `admin/b`, so `/admin` itself is never a Filament route and `/admin/platform` can never
  collide with a building slug. Filament's panel root `/admin/b` redirects to the default tenant (existing
  `getDefaultTenant`); a platform_admin without building roles is sent to `/admin` (entry).
- Redirect routes are registered in `routes/web.php` as `Route::permanentRedirect`-style closures that rebuild the
  target from the matched `{rest}` (`where('rest', '.*')`) and append `?{query}` only for GET/HEAD; other methods get
  `308` (no Filament form posts to old paths exist — Livewire posts to `/livewire/update`). Redirects never echo
  secrets (none of these routes carries one).
- Resource / page **slugs stay identical**, so every old deep link maps 1:1 (`/manage/puces-51/memberships` →
  `/admin/b/puces-51/memberships`).
- `PanelDefaults::homeUrl` stays `route('home.redirect')` for "back to the house app"; the brand link in both panels
  points to `/admin`. Brand name: **"Mājas Palīgs · Administrēšana" / "Mājas Palīgs · Администрирование"**.
- Resident app: "Vairāk" sheet gets an item **"Administrēšana / Администрирование"** (icon `shield-check`) visible
  only when `UserScopedQuery::for($user)->manageBuildingIds() !== []` or platform_admin → `/admin`.
- Everything else stays as is: tenancy (`SetCurrentBuilding` persistent tenant middleware, allow-list of
  `set()/runFor()` callers), policies, audit chains, step-up levels (any < mfa < strong, ADR 0006), CSP debt
  SEC-004.1, global search off.

### 1.2 Navigation of the building panel (per role)
Navigation groups (LV / RU): **Sākums** / Главная, **Cilvēki** / Люди, **Māja** / Дом, **Siltums un ūdens** / Тепло и
вода, **Stāvvieta** / Парковка, **Pārvaldība** / Управление.

| Group | Item (slug) | building_admin | section_elder | parking_officer |
|---|---|---|---|---|
| Sākums | Dashboard (gate cards, team card, next steps) | ✔ | ✔ (own stairwells) | ✔ (parking cards) |
| Cilvēki | **Komanda** (`team`) | ✔ edit | ✔ read-only list of current role holders (role + stairwell + name/e-mail hint) | ✔ read-only |
| Cilvēki | Dalības (`memberships`) | ✔ | ✔ own stairwells | — |
| Cilvēki | **Iedzīvotāju saraksts** (`roster`) | ✔ | ✔ own stairwells | — |
| Cilvēki | Ielūgumu vēstules (`invite-batches`) | ✔ | — | — |
| Māja | Kāpņu telpas, Dzīvokļi, Stāvadi | ✔ | read (own) | — |
| Siltums un ūdens | Apsekojumi, Atslēgumi, Strīdi, Pārskati | ✔ | ✔ own | — |
| Stāvvieta | **Stāvvietas statuss** (`parking-status`, always first) | ✔ | — | ✔ |
| Stāvvieta | Caurlaides, Auto, Stāvlaukumi, Ziņojumi, Uzdevumi, Analītika, Eksports, **Imports** | ✔ (≥ L1) | — | ✔ (≥ L1) |
| Stāvvieta | Zīme (`parking-sign`) | ✔ (L0+) | — | ✔ (L0+) |
| Pārvaldība | **Mājas atvēršana** (`opening`) | ✔ edit | ✔ read | ✔ read (parking steps) |
| Pārvaldība | Dokumenti (`documents`) | ✔ | — | ✔ DPIA read |
| Pārvaldība | Privātuma paziņojums, Iestatījumi, Audits, Mājas datu eksports | ✔ | — | — |

- "Iestatījumi" keeps only operational settings (modules, enforcement mode, sharing flags, manager contact, locale).
  Controller / DPIA / mandate fields move into the wizard (§3) — the settings page shows them read-only with a link
  "Mainīt atvēršanas vednī". One writer per field avoids two code paths to the gates.
- Below L1 the parking group shows **only** "Stāvvietas statuss" and "Zīme" (never an empty group, never hidden for
  admins/officers — R2). `canAccess()` of the L1 resources is unchanged (policy `before()`), only the nav changes.

### 1.3 Building switcher
Filament tenant menu (built in) lists `getTenants()` (unchanged, roles only). Add a tenant-menu item "Visas mājas"
→ `/admin`, and for platform_admin "Platforma" → `/admin/platform`. The platform panel's user menu gets "Mājas
administrēšana" → `/admin`. No building appears in a switcher without a role in it (platform_admin included — cross-tenant
reads stay BreakGlass only).

---

## 2. Komanda — inviting and handing over administration (ADMIN-006)

### 2.1 Data model — `role_invitations` (tenant table)
| Column | Type | Notes |
|---|---|---|
| id | ulid PK | used in audit `subject_id` |
| building_id | bigint NOT NULL FK | index `(building_id, created_at)`; `UNIQUE(building_id, id)` |
| email_ciphertext | text | Laravel `encrypted` cast (APP_KEY) — shown to building_admin only |
| email_hmac | bytea(32) | HMAC-SHA256(`INVITE_KEY`, "role-invite:" ‖ lower(trim(email))) — match on accept, duplicate check |
| email_hint | text | `m•••a@g•••.com` for lists, mails to other admins, audit rendering |
| role | text | CHECK `building_admin|section_elder|parking_officer|volunteer` (never `manager_viewer`) |
| section_ids | int[] NOT NULL default `{}` | CHECK `role <> 'section_elder' OR cardinality(section_ids) > 0`; reuse `assert_section_ids_in_building()` trigger (HEAT-003) |
| mandate_ref | text null | ≤ 200 |
| locale | text | `lv|ru` — mail + accept page language |
| token_hash | bytea(32) UNIQUE | sha256 of the 32-byte random token; the token itself only in the mail |
| invited_by | bigint FK users null on delete | |
| created_at, expires_at | timestamptz | expiry 7 days |
| accepted_at, accepted_user_id | timestamptz null, bigint null | |
| revoked_at, revoked_by | timestamptz null, bigint null | |
| resent_at, resend_count | timestamptz null, smallint default 0 | resend = new token (old hash replaced), max 3 |

Constraints: partial UNIQUE `(building_id, email_hmac, role, section_ids)` WHERE `accepted_at IS NULL AND revoked_at IS
NULL`; CHECK `accepted_at IS NULL OR revoked_at IS NULL`. Retention: `prune:role-invitations` (daily, `pgsql_retention`)
deletes rows 30 days after accepted / revoked / expired (audit keeps the history, no e-mail in audit `data`).
Grants: app SELECT/INSERT/UPDATE (no DELETE), retention DELETE (GrantsCoverageTest).

### 2.2 Flows (`App\Services\Team\TeamInvitationService`)
1. **Invite** (`TeamPage` → "Uzaicināt" modal): e-mail, role, stairwell(s) (elder: multi-select, own building
   sections only), mandate ref (optional), mail language. Requires building_admin + **strong** step-up
   (`StepUpService::demandStrong`, same as granting a role to someone else today). Refused when: an active role of the same
   kind already exists for that e-mail's account (`role_already_held`), an open invitation exists (`invite_pending` —
   offer "Sūtīt vēlreiz"), the e-mail is the actor's own (`self_invite`), > 20 invitations/day per building
   (`team_invite_quota`). Audit `team.invite.create` {role, section_ids, invitation_id}. Mail sent in `locale`.
2. **Accept** (`GET /team-invite/{slug}`, Livewire `Team\AcceptInvite`): Alpine reads `#t=` → POST to the component
   action (token as action argument, never a public property — same pattern as Parking\Lookup). Token → `token_hash`
   lookup **inside the building context** of the bound slug (no new scope lift). Invalid / expired / revoked / used →
   one neutral text "Uzaicinājums vairs nav derīgs — palūdziet jaunu" (identical response, throttled 10/15 min per device).
   Then:
   - Not signed in → page shows the role, the building and the e-mail hint, button "Apstiprināt ar e-pastu" →
     `MagicLinkService` issues a 15-min code with a new purpose **`LoginTokenPurpose::TeamInvite`** (bound to the
     invitation id, same hashing / attempt limits / 10-per-24 h account cap as `login`) to the invitation e-mail; the
     token waits in the session (encrypted session, cleared on use). Unlike `/login` (enumeration-safe, never creates
     users), a verified `team_invite` code **creates** the account when no user has that e-mail (email verified,
     locale = invite locale), after the 18+ confirmation and the platform account notice (same components as `/join`
     ChooseAccess); an existing account is simply logged in (LoginCompleter, remember choice offered).
   - Signed in with a different e-mail → "Šis uzaicinājums ir citai e-pasta adresei (m•••a@…). Izejiet un ieejiet ar
     to." Never accept across e-mails.
   - Signed in with the matching e-mail (hash compare) → "Pieņemt lomu" → `BuildingRoleService::grant()` per section
     with `actor = invited_by`, flag `viaInvitation: true` (the strong check happened at issue time and is recorded on
     the invitation; `grant()` skips its own strong check **only** with a valid, unexpired invitation id passed in
     from the service — unit-tested backstop). Sets `accepted_at`, audit `team.invite.accept` with
     `subject_user_ids=[new holder]`, notifies the inviter and every other building_admin (count-free text "Komandai
     pievienots jauns administrators"). Elevated roles → redirect to `/me/passkey` (offer passkey, ADR 0006), then
     `/admin`.
3. **Resend** — new token, `resend_count+1`, expiry reset to 7 days, strong step-up. **Revoke** — `revoked_at`, mfa
   step-up, audit. **Expire** — implicit (`expires_at`), listed as "Novecojis".
4. **Remove a role holder** — existing `BuildingRoleService::revoke` (epoch bump stays; FIX-004.1 is still open).
   **Last-admin guard:** revoking the last active building_admin of a building is refused
   (`last_building_admin`), unless the actor is platform_admin in the platform panel. Self-revoke allowed when another
   active building_admin exists ("Nodot administrēšanu" flow: invite → wait for acceptance → "Atteikties no savas
   lomas").
5. **Re-confirm** — "Pagarināt uz 12 mēnešiem" on a holder (existing re-confirmation path, mfa step-up).

### 2.3 TeamPage UI (`App\Filament\Manage\Pages\TeamPage`, slug `team`)
- Header: "Komanda / Команда" + one-line explainer "Šeit nosakāt, kas palīdz pārvaldīt māju. Katra loma dod tikai savas
  tiesības." Button "Uzaicināt" (admin only).
- Section **Lomas** (table, mobile = stacked cards): role badge (icon + word), stairwell(s), holder (display name if
  set, else e-mail hint; full e-mail to building_admin only), passkey/TOTP present (✔ icon + word "Aizsargāts" /
  "Nav otrā faktora" warning), expires, actions (Pagarināt, Noņemt).
- Section **Gaida apstiprinājumu** (pending invitations): e-mail (admin: full; others: hint), role, stairwell(s), sent,
  expires ("vēl 5 dienas"), actions (Sūtīt vēlreiz, Atsaukt).
- Section **Ko dara katra loma** (static help, LV/RU): building_admin, section_elder, parking_officer, volunteer — one
  sentence each, incl. "nevar redzēt auto numurus" for elders (PLANNING §4.4).
- Empty states: "Pagaidām komandā esat tikai Jūs. Uzaiciniet kāpņu telpu vecākos — viņi apstiprina kaimiņus."
- BuildingRoleResource stays (grant from approved members) but is removed from navigation; TeamPage links to it as
  "Piešķirt lomu esošam iedzīvotājam".

### 2.4 Policies
`RoleInvitationPolicy`: viewAny/view → any manage role of the building (others see hints only); create/resend/revoke
→ building_admin; accept → handled by the service (token + e-mail hash), not a policy on the invitee.
`BuildingRolePolicy::revoke` gains the last-admin guard. Platform: `BuildingResource` "bootstrapAdmin" may also
issue an invitation (`PlatformBuildingContext::run(…, RoleBootstrap)`), so the platform admin never needs the owner's
account to exist first.

---

## 3. House opening wizard and templates (GOV-010, GOV-011, GOV-012, UI-011)

### 3.1 Gates after round 2 (pure functions of `buildings.settings` + `building_privacy_notices`)
| Gate | Conditions | Unlocks |
|---|---|---|
| **L-core** (`CoreGate`, unchanged) | controller name + contact, `dpa_signed_at`, `dpa_ref`, `dpa_scope ⊇ {core, heating, outages}`, current notice covering core/heating/outages | `/join`, invite letters, memberships, heating, outages |
| **L-R** (`RosterGate`, new) | L-core + `dpa_scope ∋ roster` + current notice covers `roster` | any write/read of `resident_contacts`, roster import upload/commit, roster export |
| **Parking L1** (unchanged) | L-core + `dpa_scope ∋ parking` | staff parking data, permit import upload/commit |
| **Parking L2** (unchanged) | L1 + `dpia_completed_at` + notice covers `parking` + `mandate_ref` | `modules.parking` switch, resident parking |

`RosterGate` mirrors `ParkingGate`: `status(Building): RosterGateStatus{open, missing[]}` (keys `core_gate`,
`dpa_scope_roster`, `privacy_notice_roster`), `assertOpen()`, model guard `GuardedByRosterGate` on `ResidentContact`
(saving/deleting; retention + erasure deletes exempt like parking), `RosterGateMonitor` (regression → audit
`gate.roster.closed`, alert building_admin + platform admin; data stays but becomes unreadable in the UI until the gate is
open again — "Saraksts slēgts: …"). Enforcement three times (policy `before()`, model guard, UI), like C.0.

### 3.2 Wizard (`App\Filament\Manage\Pages\HouseOpeningWizardPage`, slug `opening`)
A vertical checklist (not a modal stepper — it must survive interruptions over days): one card per step with status
(icon + word: "Izdarīts / Готово", "Jāizdara / Нужно сделать", "Gaida CoreDigify / Ждёт CoreDigify", "Nav obligāts /
Необязательно"), "Kāpēc? / Зачем?" disclosure (plain-language text below), "Kas to dara? / Кто делает?" line, and the
action. Progress header per gate: "Pieteikšanās atvēršana: 3 no 4", "Iedzīvotāju saraksts: 1 no 3", "Stāvvieta
iedzīvotājiem: 2 no 6". Deep link per step `#step-<key>` (dashboard cards link here). Every save: building_admin +
mfa step-up, audited (`opening.<step>`), and run through `CoreGateMonitor` / `ParkingGateMonitor` / `RosterGateMonitor`
before/after diffs (existing pattern of BuildingSettingsPage::save).

| Key | Gate condition(s) | Who | Action in the wizard |
|---|---|---|---|
| `structure` | — (info) | platform / admin | Shows sections × floors × apartments and riser confidence; link to the generator (platform) or "Dzīvokļi". |
| `team` | — (info) | admin | Count of elders per stairwell; link to Komanda. |
| `controller` | `controller_name`, `controller_contact` | admin | Form: name (person / kopība / biedrība), public contact (e-mail or address — not a private phone; helper text), basis ref ("Kopības lēmums Nr. …", "Pilnvara …"). |
| `dpa` | `dpa_signed_at`, `dpa_ref`, `dpa_scope …` | admin prepares, **CoreDigify records** | Choose scope (core, heating, outages pre-ticked; parking; roster) → generate DPA from template (LV + RU) → edit allowed blocks → "Gatavs parakstīšanai" (document `final`, PDF) → "Nosūtīt CoreDigify" (status `sent`, platform admins notified). Card then reads "Gaida CoreDigify". The platform admin records date + ref + scope (existing DPA action, now opened from the request, §3.6). A later scope extension (e.g. + roster) = a new DPA version/annex document with the same flow. |
| `notice` | `privacy_notice` (+ `privacy_notice_parking`, `privacy_notice_roster`) | admin | Pick the template version, edit allowed blocks, preview LV/RU with controller filled in, choose covered modules (only modules inside the recorded `dpa_scope` are selectable), publish (previous notice withdrawn — existing BuildingPrivacyNoticePage logic moves here; the page stays as a read-only history list). |
| `core_open` | — (result) | — | When L-core is open: "Māja atvērta pieteikšanai" + next steps: print invite letters, invite elders. |
| `roster_scope` | `dpa_scope_roster` | CoreDigify | Same DPA flow (annex) — card explains it. |
| `roster_notice` | `privacy_notice_roster` | admin | Republish the notice with the roster section. |
| `roster_inform` | — (non-blocking checklist) | admin | Art. 14: residents whose data comes from the house's old list must be informed within one month — tick "Paziņojums izsūtīts / izlikts" (date stored `settings.roster_informed_at`); 30 days after the first roster import without it the dashboard shows a reminder. |
| `parking_scope` | `dpa_scope_parking` (→ L1) | CoreDigify | As `roster_scope`. |
| `dpia` | `dpia_completed_at` | admin (+ officer reads) | DPIA-lite checklist (§3.5.3), answers stored on the document; "Pabeigt" sets `dpia_completed_at = today`, document `final`. Re-open = new version, gate unchanged until a new completion date. |
| `mandate` | `mandate_ref` | admin | Text ref ("Kopības lēmums Nr. … no dd.mm.gggg" / "Pilnvarotā persona …") + link to the neutral decision template (GOV-006, downloadable LV/RU). |
| `parking_notice` | `privacy_notice_parking` | admin | Republish notice with the parking section. |
| `parking_on` | `modules.parking` | admin | Toggle (only enabled at L2 — existing rule), explains what residents will see. |

**"Kāpēc?" copy (LV / RU, final wording to native RU review; keys `opening.why.<key>`):**
- `controller` — LV: "Kādam ir jāatbild par kaimiņu datiem. Tā ir māja (kopība vai tās pilnvarota persona), nevis
  CoreDigify. Iedzīvotāji redzēs šo vārdu un kontaktu, ja gribēs ko jautāt par saviem datiem." RU: "Кто-то должен
  отвечать за данные соседей. Это дом (сообщество собственников или уполномоченное лицо), а не CoreDigify. Жители увидят
  это имя и контакт, если захотят спросить о своих данных."
- `dpa` — LV: "CoreDigify glabā datus mājas uzdevumā. Līgums (DPA) nosaka, ka mēs tos izmantojam tikai mājas vajadzībām,
  sargājam un izdzēšam, kad māja to lūdz. Bez līguma mēs nedrīkstam glabāt neviena kaimiņa datus." RU: "CoreDigify хранит
  данные по поручению дома. Договор (DPA) определяет, что мы используем их только для нужд дома, защищаем и удаляем по
  просьбе дома. Без договора мы не имеем права хранить данные соседей."
- `notice` — LV: "Pirms kaimiņš ievada savus datus, viņam jāzina, kas tos redz, cik ilgi tos glabā un kur vērsties.
  Paziņojums tiek parādīts pieteikšanās brīdī un ir pieejams vienmēr." RU: "Прежде чем сосед введёт свои данные, он должен
  знать, кто их видит, сколько их хранят и куда обращаться. Уведомление показывается при регистрации и доступно всегда."
- `roster_inform` — LV: "Kontakti no mājas vecā saraksta nav nākuši no paša kaimiņa, tāpēc viņam mēneša laikā jāpaziņo,
  ka māja tos glabā šeit." RU: "Контакты из старого списка дома получены не от самого соседа, поэтому в течение месяца
  ему нужно сообщить, что дом хранит их здесь."
- `dpia` — LV: "Auto numuri un ziņojumi par auto var radīt strīdus starp kaimiņiem. Īss risku novērtējums parāda, ka
  esam padomājuši, kā novērst ļaunprātīgu izmantošanu." RU: "Номера машин и сообщения о машинах могут вызвать споры между
  соседями. Короткая оценка рисков показывает, что мы продумали, как предотвратить злоупотребления."
- `mandate` — LV: "Stāvvietas kārtību nosaka māja, nevis viens kaimiņš. Ierakstiet lēmumu vai pilnvaru, uz kuras
  pamata to darāt." RU: "Порядок на парковке устанавливает дом, а не один сосед. Укажите решение или доверенность, на
  основании которой вы это делаете."

### 3.3 Data model
**`document_templates`** (platform table, no building_id): id, kind CHECK `dpa|dpa_annex|privacy_notice|dpia_lite|
parking_decision`, version int, locale `lv|ru`, title, body text (Markdown with markers §3.4), required_markers text[],
placeholders text[], covers_modules text[] (notice only), published_at null, created_by, timestamps.
UNIQUE(kind, version, locale). Seeded by `DocumentTemplateSeeder` from `database/templates/{kind}.{locale}.md`
(tracked files, synthetic placeholders only). Platform `DocumentTemplateResource`: draft → publish new version (old
versions immutable after publish — trigger rejects UPDATE of body when published_at IS NOT NULL).
The existing `privacy_notice_versions` (kind `building_template`) **stays the notice store** for gates and `/join`;
`privacy_notice` document templates feed it: publishing a building notice writes `building_privacy_notices` as today,
plus the new columns below.

**`building_documents`** (tenant): id ulid, building_id, kind (as above), template_id FK, version int (per building +
kind), body_lv text, body_ru text (the edited copies), answers jsonb null (DPIA-lite checklist), meta jsonb
(`{requested_scope: [...], covers_modules: [...]}`), status CHECK `draft|final|sent|recorded|superseded`,
finalized_by/at, sent_at, recorded_by/at (platform), superseded_by null, timestamps. UNIQUE(building_id, kind,
version), UNIQUE(building_id, id). Bodies hold **no personal data** except the controller name/contact placeholders
the house typed; the linter (§3.4) refuses phone-shaped strings outside `{controller.contact}`.

**`building_privacy_notices`** add: `document_id` ulid null → composite FK `(building_id, document_id)` →
`building_documents`; `body_override` jsonb null `{lv, ru}` (rendered instead of the template when set). Alter CHECK
`covers_modules <@ ARRAY['core','heating','outages','parking','roster']`. `privacy_notice_versions.covers_modules` CHECK
likewise (+ `account`).

**`buildings.settings`** (BuildingSettings DTO): `DPA_SCOPES` += `roster`; new keys `roster_informed_at` (date null),
`opening_completed_at` (set once when L-core first opens; display only). No other gate input changes.

### 3.4 Template authoring rules
- Markers: `<!-- req:<key> -->` … `<!-- /req -->` = **locked** block (rendered, not editable); `<!-- edit:<key> -->`
  … `<!-- /edit -->` = editable block; text outside markers is locked. Placeholders `{controller.name}`,
  `{controller.contact}`, `{controller.basis_ref}`, `{building.name}`, `{building.address}`, `{dpa.scope_list}`,
  `{dpa.ref}`, `{processor.name}`, `{processor.contact}`, `{subprocessors.list}` (platform config
  `majas.processor.*`), `{retention.table}` (rendered from one PHP source `App\Gdpr\RetentionTable`, so the notice and
  PLANNING §5 can never drift).
- The editor (`DocumentEditor` Livewire inside the wizard) shows locked blocks as grey read-only cards and editable
  blocks as textareas (≤ 4000 chars each), LV and RU side by side on ≥ 1024 px, tabs on phones.
- `TemplateLinter::check(document)`: every `required_markers` key present and unchanged (hash of the locked block),
  no unknown placeholders, LV and RU both non-empty for every editable block, no raw HTML (Markdown subset: headings,
  lists, bold, links to https only), no phone-shaped or plate-shaped strings outside placeholders. Finalise / publish is
  refused with a per-block error list.
- Rendering: Markdown → sanitised HTML (league/commonmark, `html_input: strip`, `allow_unsafe_links: false`), PDF via
  dompdf + DejaVu Sans (A4, LV and RU as separate documents).
- Legal disclaimer on every template card: "Paraugs, nevis juridiska konsultācija. Pirms parakstīšanas to var pārbaudīt
  jurists." (MANUAL-TODO: legal + native RU review of the masters before the pilot uses them.)

### 3.5 Template contents (masters, LV and RU)
**3.5.1 DPA (Art. 28 GDPR)** — locked: parties (controller placeholders; processor CoreDigify), subject matter and
duration (until termination + 30 days), nature and purpose per module (core: membership and roles; heating/outages:
reports, aggregates; parking: permit and vehicle register, lookup, sightings; roster: contact register for house
administration), categories of data per module, categories of data subjects (residents, owners, tenants, role holders),
processor obligations (documented instructions only; confidentiality; Art. 32 measures = annex TOMs rendered from
`docs/SECURITY.md` headings; sub-processors list + 14-day prior notice of changes and right to object; assistance with
data-subject requests and DPIA; breach notice to the controller without undue delay and at the latest 48 h after
becoming aware; deletion or return at the end — building export + deletion within 30 days; audits on reasonable
notice; EU-only storage). Editable: contact persons, notice addresses, special instructions, signature block.
Annex A: scope modules (`{dpa.scope_list}`); Annex B: retention (`{retention.table}`); Annex C: TOMs.
**3.5.2 Building privacy notice (Art. 13/14)** — locked: controller + contact, processor, purposes and legal bases per
module (core 6(1)(f) house administration / 6(1)(c) where the Apartment Ownership Law applies; heating/outages 6(1)(f);
parking 6(1)(f) after the house decision (`mandate_ref`); roster 6(1)(f) — contacting owners/tenants about the house,
with the LIA summary), data categories, **sources** (roster: "mājas iepriekšējais kontaktu saraksts vai pats iedzīvotājs";
permits: "caurlaižu izsniedzēja saraksts"), recipients (manager only aggregates unless the house decided otherwise;
police list only in enforcement modes B/C), retention (`{retention.table}`), rights (access, rectification, erasure,
restriction, objection; "Kas skatīja manus datus"), complaint to Datu valsts inspekcija, no automated decisions, no
transfers outside the EU/EEA. Sections `parking` and `roster` are included only when the notice covers those modules.
Editable: how to reach the controller in person, house-specific notes.
**3.5.3 DPIA-lite (parking; reusable for roster)** — structured `answers` (each: answer enum + note ≤ 500):
1 processing description (pre-filled), 2 necessity and proportionality (lookup yes/no only, no OCR, no public feed),
3 EDPB WP248 criteria check (systematic monitoring? sensitive data? vulnerable subjects? — pre-filled "nē" with
reasons), 4 risks with likelihood/severity (plate enumeration, vigilante misuse / shaming, reveal abuse by staff, data
breach, function creep to the manager or police), 5 measures (pre-filled from existing controls: quotas, neutral
results, reveal reason + cap + subject visibility, four-eyes export, retention, encryption), 6 residual risk +
acceptance, 7 review date (default +12 months), 8 sign-off (name of the authorised person typed by the admin).
Completion requires every item answered and the residual-risk acceptance ticked.
**3.5.4 Parking decision template (GOV-006)** — neutral options (one or more permits per apartment, guest permit rule,
amnesty window, data use); download only, never "binding".

### 3.6 Platform side — "Atvēršanas pieprasījumi" (`App\Filament\Platform\Pages\OpeningRequestsPage`)
Queue of `building_documents` with status `sent` across buildings. Not BreakGlass (that is read-only and
reason-gated): a new platform purpose `PlatformPurpose::OpeningReview` and `PlatformBuildingContext::run` per building.
The list itself comes from `BuildingIterator` (sanctioned, sets the context per building and runs a scoped query for
`sent` documents), so no new scope lift and no new context setter is added. Shows building, kind, requested scope, PDF preview; action "Ierakstīt parakstīto DPA"
pre-fills the existing DPA action (date, ref, scope = requested scope) and marks the document `recorded`. Audit on the
platform chain. Notifies the building's admins "CoreDigify ierakstīja līgumu — nākamais solis: paziņojums".

### 3.7 Dashboard and parking placeholder (UI-011)
- `GateStatusWidget` (replaces the single "L-core" stat in BuildingOverview): three cards — **Pieteikšanās** (L-core),
  **Iedzīvotāju saraksts** (L-R), **Stāvvieta** (L0/L1/L2 + module on/off). Each: status chip (icon + word, never colour
  alone), "Trūkst:" list of up to three missing items in plain words (+ "un vēl N"), primary button "Turpināt" →
  `opening#step-<first missing>`. Officers see only the parking card; elders see L-core + roster (read-only text).
- `TeamWidget`: role counts, elders per stairwell ("5. k.t.: nav vecākā"), pending invitations.
- `ParkingStatusPage` (slug `parking-status`, admin + officer, always visible): explains L0/L1/L2 in two short
  paragraphs, shows exactly what is missing with links to the wizard steps, what residents currently see ("Iedzīvotāji
  stāvvietu neredz"), and between L1 and L2 "Jūs varat sagatavot caurlaides; iedzīvotāji tās redzēs pēc L2".
- Resident app is unchanged (parking nav still only at L2 + module on — residents must not see a half-open module).

### 3.8 Policies
`BuildingDocumentPolicy`: view → building_admin; parking_officer for `dpia_lite` / `parking_decision`; edit /
finalize / send → building_admin + mfa; record → platform only (platform panel). `HouseOpeningWizardPage::canAccess`
→ any manage role (view); each step action re-checks `BuildingPolicy::update` (admin). `DocumentTemplatePolicy` →
platform_admin.

---

## 4. CSV import pipeline (shared by roster and permits) (DB-003)

### 4.1 Stages
`Upload → Kartēšana (mapping) → Pārbaude (preview + validation) → Izmēģinājums (dry-run counts) → Imports (commit)`,
shown as a 5-step progress bar on one Filament page per kind: `RosterImportPage` (slug `roster/import`) and
`PermitImportPage` (slug `permit-import`, rebuilt). Each stage is resumable for 24 h (`import_runs` row).

### 4.2 Data model
**`import_runs`** (tenant): id ulid, building_id, kind CHECK `roster|permits`, status CHECK
`uploaded|mapped|validated|committing|committed|failed|discarded|expired`, created_by, file_sha256 bytea(32),
encoding CHECK `utf-8|windows-1257|windows-1251`, delimiter CHECK `;|,|tab`, has_header bool, row_count int, mapping
jsonb (`{"0": "apartment_number", "1": "name", …}`), options jsonb (`{mode: create_only|create_or_update,
endorsed: bool}`), payload_ciphertext bytea null (the CSV sealed with `ImportVault`, §4.6), summary jsonb (counts per
validation code — **no values**), committed_counts jsonb null, error_code text null, expires_at (created + 24 h),
timestamps. UNIQUE(building_id, id); index(building_id, kind, created_at). Partial UNIQUE(building_id, kind) WHERE status
= 'committing' (one commit per building + kind at a time). Payload is nulled on commit / discard / expiry
(`prune:imports` hourly, `pgsql_retention`); rows deleted after 30 days.
**`import_mapping_presets`** (tenant): building_id, kind, header_signature bytea(32) (sha256 of the normalised header
list), mapping jsonb, updated_by, updated_at; PK(building_id, kind, header_signature). Headers are not personal data;
a preset re-applies automatically when the same sheet layout is uploaded again.

### 4.3 Parsing rules (`App\Services\Import\CsvReader`)
- Input: file upload (Filament FileUpload, private `local` disk tmp, never `public`) **or** paste (textarea). ≤ 2 MB,
  ≤ 2000 data rows, ≤ 30 columns, ≤ 1000 chars per cell; extension `.csv`/`.txt`, finfo `text/plain|text/csv|
  application/csv`; anything else → `file_type`. The temp upload is read once, sealed into `payload_ciphertext` and
  deleted in the same request.
- Encoding: strip UTF-8 BOM; valid UTF-8 → `utf-8`; else heuristics (Latvian diacritic byte frequencies vs Cyrillic
  range) pick `windows-1257` or `windows-1251` and the page shows "Faila kodējums: … — pārbaudiet burtus priekšskatījumā"
  with a selector to change it. Output always UTF-8 NFC.
- Delimiter: sniff `;`, `,`, TAB over the first 10 lines outside quotes (RFC 4180 quoting, `""` escape, multi-line
  quoted cells allowed); selector to override. Google Sheets export = `,` + UTF-8; Excel LV "CSV UTF-8" = `;` + BOM.
- Header row: auto-detected when ≥ 50 % of first-row cells match a synonym (§4.4); toggle "Pirmā rinda ir virsraksti".
- Cell normalisation: trim, collapse inner whitespace, strip a leading `'` (Excel text prefix), treat `-`, `—`, `n/a`,
  `nav` as empty. Never evaluate formulas; values starting with `= + - @` are stored as text and re-escaped on every CSV
  export (`CsvWriter`, existing).

### 4.4 Mapping (`App\Services\Import\ColumnMapper`)
Auto-suggest by normalised header (lowercase, unaccented, punctuation stripped) against synonyms; every target field may
be mapped once; unmapped columns are ignored (shown greyed "netiks importēts"). Required fields block "Tālāk" until
mapped.

| Target (roster) | Synonyms (LV / RU / EN) |
|---|---|
| `apartment_number` * | dzivoklis, dz, dz nr, dzivokla nr / kvartira, kv, nomer kvartiry (Cyrillic forms incl. "кв", "квартира") / apartment, apt, flat |
| `name` | vards, vards uzvards, kontaktpersona / imya, fio ("имя", "фио") / name, contact |
| `phone` (multi) | telefons, talrunis, talr, mob / telefon ("телефон", "тел") / phone, mobile |
| `email` | epasts, e-pasts, e pasts / pochta ("почта", "e-mail") / email |
| `relation` | statuss, loma, ipasnieks/irnieks / status ("статус", "собственник") / relation, owner/tenant |
| `bis_registered` | bis, bis registrets / bis ("БИС") / bis |
| `whatsapp_member` | whatsapp, wa, whatsapp grupa / whatsapp ("ватсап") / whatsapp |
| `comment` | komentars, piezimes / kommentarij ("комментарий", "примечание") / comment, notes |

| Target (permits) | Synonyms |
|---|---|
| `permit_number` * | caurlaide, caurlaides nr, nr / propusk ("пропуск", "номер пропуска") / permit, permit no |
| `type` * | veids, tips, pamata/viesu / tip ("тип", "основной/гостевой") / type, main/guest |
| `apartment_number` * | as roster |
| `plate` | auto, numurs, auto numurs, valsts numurs / nomer avto ("номер машины", "госномер") / plate |
| `valid_from`, `valid_to` | no, lidz, derigs lidz / s, do ("с", "до", "действует до") / from, to, valid until |
| `status` | statuss / status / status |
| `issued_by` | izsniedza / vydal ("выдал") / issued by |
| `company_car` | uznemuma auto / sluzhebnaya ("служебная") / company car |

### 4.5 Validation (`App\Services\Import\RowValidator` per kind) — codes, never values
Per row: `ok`, `warning` (imported), `error` (skipped). Shared codes: `apartment_not_found`, `apartment_merged`
(warning: retired number mapped to the primary apartment), `required_missing`, `too_long`, `duplicate_in_file`,
`exists_skip` (create_only), `exists_update` (create_or_update).
Roster: `phone_invalid`, `phone_foreign` (warning: valid E.164 outside +371), `phone_too_many` (> 3, extra dropped,
warning), `email_invalid`, `relation_unknown` (warning → `contact`), `flag_unparsed` (warning → unknown),
`comment_sensitive_hint` (warning when the comment matches a small deny-list: health words, debt, "parāds", "slims" …,
LV/RU — the admin is asked to shorten it).
Permits: `type_unknown`, `plate_invalid` (PlateNormalizer), `main_without_plate`, `plate_conflict` (active in another
apartment — no plate echo), `number_overlap` (EXCLUDE would fire), `date_invalid`, `period_invalid`, `status_unknown`,
`apartment_change_refused` (update mode never moves a permit to another apartment).
Phone rules (`App\Support\PhoneNormalizer`): strip spaces, dots, dashes, brackets; `00` → `+`; 8 digits starting with
`2` or `6` → `+371` prefix; Latvia valid = `+371` followed by 8 digits whose first digit is 2 (mobile) or 6 (landline);
other `+[1-9][0-9]{6,14}` = foreign warning; cell split on `,`, `;`, `/`, `|`, " un ", " и ". Dates: `dd.mm.yyyy`,
`d.m.yy`, `yyyy-mm-dd`, Excel serial numbers (warning). Yes/no: `jā, ja, yes, y, 1, x, +, ✓, да` / `nē, ne, no, n,
0, нет`; empty = unknown.

### 4.6 Preview, dry-run, commit
- **Preview** renders server-side from the sealed payload: 50 rows per page, status column (icon + word), code
  explanations in plain LV/RU, filter "tikai kļūdas". Sensitive cells are **masked in the preview** (phones `+371 2•••••45`,
  e-mails `j•••@…`, plates `AB••34`); names shown. No row values in Livewire public properties — only `#[Locked]
  runId`, page number and filter; rows come from a computed property.
- **Dry-run** (button "Izmēģināt") re-validates against the database state (existing contacts / permits, plate
  conflicts via bidx) and shows exact counts: "Tiks izveidoti 142, atjaunināti 6, izlaisti 3 (kļūdas)". It writes
  only `import_runs.summary`.
- **Commit** ("Importēt"): requires status `validated`, a dry-run younger than 30 min, mfa step-up, and confirmations —
  roster: "Saprotu, ka kaimiņi jāinformē (Art. 14)"; permits: "Caurlaižu izsniedzējs ir piekritis" (existing
  endorsement). Dispatches `CommitImport` (TenantAware job, `RestoreBuildingContext`), status `committing`, page polls
  every 2 s ("Importē… 120 / 151"). The job holds `pg_advisory_xact_lock('import:'||building_id||':'||kind)` per chunk
  of 100 rows, **re-validates each row inside its savepoint** (state may have changed since the dry-run), writes via the
  domain services (`RosterService`, `PermitService`, `VehicleRegistry` — never raw inserts), and records
  `committed_counts`. Idempotent: a second dispatch for a run not in `validated` exits. Re-uploading the same
  `file_sha256` within 24 h shows "Šis fails jau importēts dd.mm. hh:mm — turpināt?".
- Audit: `import.upload`, `import.commit` with {kind, rows, created, updated, skipped} — counts only, never values,
  never file names (a file name can contain a person's name).
- **ImportVault**: XChaCha20-Poly1305 with `CONTACT_ENC_KEY` (roster) or `PLATE_ENC_KEY` (permits), AD
  `mp:import:v{n}|b:{building}|r:{run_id}|k:{kind}`; logs and GlitchTip breadcrumbs never contain cell values
  (RedactSecrets extended; test).

### 4.7 CSV templates
`GET /admin/b/{slug}/files/import-template/{kind}.csv` (admin; roster also elder) → generated at runtime: header row
in the user's language (`Dzīvoklis;Vārds;Telefons;E-pasts;Statuss;BIS;WhatsApp;Komentārs` / RU equivalents) and one
example row of obviously synthetic values built in code (e.g. apartment `1`, name `Paraugs`, phone placeholder text
"+371 …" with letters, not digits) so no phone-shaped string is ever tracked in git (personal-data guard). UTF-8 with
BOM, `;` delimiter (opens correctly in Excel LV and Google Sheets).

---

## 5. Resident roster — "Iedzīvotāju saraksts" (ADMIN-007, SEC-011)

### 5.1 Data model — `resident_contacts` (tenant)
| Column | Type | Notes |
|---|---|---|
| id | ulid PK | generated before sealing (AD binding) |
| building_id, apartment_id | bigint | composite FK `(building_id, apartment_id)` → apartments; index(building_id, apartment_id) |
| relation | text | CHECK `owner|tenant|contact` |
| name_ciphertext | bytea null | ContactVault, ≤ 120 chars plaintext |
| phones_ciphertext | bytea null | sealed JSON list of normalised E.164, ≤ 3 |
| phone_bidx | bytea[] NOT NULL default `{}` | one 32-byte blind index per phone; GIN index (dedup, "same phone in two apartments" hint for admins) |
| email_ciphertext | bytea null | lower-cased |
| email_bidx | bytea null | index(building_id, email_bidx); account linking + GDPR export/erasure matching |
| bis_registered, whatsapp_member | bool null | null = nezināms |
| comment_ciphertext | bytea null | ≤ 280 chars plaintext |
| key_version | smallint | ContactVault key version |
| user_id | bigint null FK users (null on delete) | link to the platform account when matched (§5.6) |
| source | text | CHECK `import|admin|elder` |
| import_run_id | ulid null | |
| reviewed_at | timestamptz null | "Pārbaudīts" by admin/elder |
| moved_out_at | timestamptz null | "Izvācies" — pruned 30 days later |
| created_by, updated_by | bigint null | |
| timestamps | | UNIQUE(building_id, id); CHECK at least one of name/phones/email present |

Grants: app SELECT/INSERT/UPDATE/DELETE (house data, editable); retention DELETE. Excluded from `BuildingExporter`
(explicit test), from manager reports, from every resident-facing query. `GuardedByRosterGate`.

### 5.2 ContactVault and keys (SEC-011)
`App\Services\Roster\ContactVault` — same construction as PlateVault: XChaCha20-Poly1305-IETF, new dedicated keys
**`CONTACT_ENC_KEY`** (+ `CONTACT_ENC_KEY_PREVIOUS` for rotation) and **`CONTACT_INDEX_KEY`**; AD
`mp:contact:v{ver}|b:{building}|t:resident_contacts|r:{row_id}|f:{field}` (field = name|phones|email|comment, so
ciphertexts cannot be swapped between columns); bidx = HMAC-SHA256(HKDF(`CONTACT_INDEX_KEY`, info "contact-bidx:" ‖
field, salt building_id), normalised value). `contacts:rekey {slug?}` per building in one transaction. `majas:keys`
(GenerateKeys) and `ProductionGuard` learn the new keys; docs/SECURITY.md key list + escrow updated. Arch test:
`ContactVault::open()` may be called only from `RosterViewer`, `RosterExporter`, `GdprExporter`, `ContactRekeyService`
and `ImportVault`.

### 5.3 UI — `ResidentContactResource` (slug `roster`)
- Default view **grouped by stairwell → apartment** (one row per apartment, contacts stacked): apartment no., floor,
  contacts (name, relation chip, phones **masked** `+371 2•••••45`, e-mail masked, BIS chip "BIS ✔ / BIS ✖ / BIS ?",
  WhatsApp chip), comment indicator (icon "Ir piezīme"), reviewed / linked-account icons. Apartments without contacts
  are listed too (empty state per row "Nav kontakta — Pievienot"), so gaps are visible.
- Filters: stairwell (elders: only own, pre-selected), floor, relation, BIS (yes/no/unknown), WhatsApp (yes/no/unknown),
  has phone / has e-mail, no contact, not reviewed > 12 months, linked account, moved out.
- Stats header (counts only): "Kontakti 151 no 180 dzīvokļiem (84 %) · BIS: 97 jā · WhatsApp: 120 jā".
- Row actions: **Rādīt kontaktus** (unmasks that apartment's phones/e-mails in a modal for 5 min, with `tel:` and
  `mailto:` links; mfa step-up; audited `roster.reveal` {apartment_id, contact_ids} with `subject_user_ids` = linked
  users; cap 100 reveals/day per user, then "Šodien parādīts daudz kontaktu — turpiniet rīt vai izmantojiet eksportu"),
  Labot, Pārbaudīts, Izvācies, Dzēst (confirm), **Datu izraksts** (Art. 15 answer for one contact: JSON + CSV of that
  contact + roster audit rows about it, signed 10 min, audited).
- Header actions: Pievienot, Importēt (→ RosterImportPage), Lejupielādēt veidni, **Eksportēt CSV** (building_admin only,
  strong step-up, audited `roster.export` {rows}, generated on demand, signed 10 min, never stored; formula-safe).
- Edit form: relation, name, phones (repeater ≤ 3, PhoneNormalizer live validation, "+371" hint), e-mail, BIS, WhatsApp,
  comment (≤ 280, helper "Nerakstiet veselības, parādu vai strīdu informāciju"). Saving re-seals all fields.
- Page view audit: `roster.list` {section_ids, rows} once per user per 10 minutes per filter set (masked list still shows
  names) — keeps "audited reads" without flooding the chain.
- Mobile: stacked cards, 48 px targets, `tel:` link only after reveal. All strings LV + RU; masked values readable by
  screen readers as "slēpts numurs, beidzas ar 45".

### 5.4 Visibility (`ResidentContactPolicy`, all with RosterGate in `before()`)
| Ability | building_admin | section_elder | parking_officer / volunteer / resident / manager | platform_admin |
|---|---|---|---|---|
| viewAny / view (masked) | all stairwells | own section_ids only (apartment.section_id) | ✖ | ✖ (BreakGlass read-only, reason, audited — masked) |
| reveal | ✔ | own sections | ✖ | ✖ |
| create / update / delete / mark reviewed / moved out | ✔ | own sections | ✖ | ✖ |
| import | ✔ | ✖ (round 2; owner can delegate later) | ✖ | ✖ |
| export | ✔ (strong step-up) | ✖ | ✖ | ✖ |
An elder of stairwell 1 gets 404 for a contact of stairwell 5 (route-model binding scoped + policy), like heating.

### 5.5 GDPR
- **Controller / basis:** house, Art. 6(1)(f) (administering the building: reaching owners and tenants about outages,
  meetings, BIS votes) — LIA summary in the notice and in `BRAIN/docs/GDPR-RECORD.md` (GOV-004 deliverable, now including
  roster); Art. 14 information (§3.2 `roster_inform`).
- **Linking:** when a user's verified account e-mail matches `email_bidx` of a contact **in an apartment where the user
  has an approved membership**, `RosterLinker` sets `user_id` (audited `roster.link`); no cross-apartment linking.
- **Self-service export** (`GdprExporter`): per building (via `UserScopedQuery::eachBuilding`) contacts with `user_id =
  me` **or** `email_bidx = bidx(my e-mail)`; decrypted fields + roster audit rows about me.
- **Erasure** (`AccountEraser`): deletes contacts with `user_id = me` or matching `email_bidx`, writes `roster.erase`
  {count}, notifies building_admin "1 kontakts dzēsts pēc iedzīvotāja pieprasījuma" (count only).
- **"Kas skatīja manus datus"**: `roster.reveal` / `roster.export` / `roster.subject_export` entries appear for linked users
  (actor role only, never the elder's name — existing WhoViewed rendering).
- **Retention:** `prune:roster` daily — `moved_out_at` + 30 days; contacts of an apartment with `archived_at`; import
  payloads (§4.2). Reminder: contacts not reviewed for 24 months are listed on the dashboard ("Pārskatiet 12 kontaktus").
  RetentionTable gains rows: roster (until moved out + 30 d or deletion request), import files (24 h), role invitations
  (30 d after closing).
- Not in: building export, manager reports, resident UI, alert payloads, logs, GlitchTip.

### 5.6 Services
`RosterService` (create/update/delete/markReviewed/markMovedOut; seals via ContactVault; audit), `RosterViewer`
(masked rows, reveal), `RosterExporter`, `RosterLinker` (hooked into membership approval and e-mail verification),
`RosterImportHandler` (implements `ImportHandler` for the pipeline), `RosterGate`, `RosterGateMonitor`.

---

## 6. Parking permit register (PARK-011, PARK-012, PARK-013)

### 6.1 Data model changes
- `permits`: add `source text NOT NULL default 'officer'` CHECK `officer|import|change_vehicle`; `import_run_id ulid
  null`; `section_id` is **not** stored (derived via apartment). Index `(building_id, status, type)`,
  `(building_id, apartment_id) WHERE status = 'active'`.
- **`permit_plate_observations`** (new, tenant): id ulid, building_id, permit_id (composite FK, guest permits only —
  CHECK enforced by trigger `permit is guest`), plate_ciphertext, plate_bidx, plate_key_version, observed_on date,
  source CHECK `import|officer`, created_by, created_at. Index(building_id, permit_id, observed_on), index(building_id,
  plate_bidx). `GuardedByParkingGate` (L1). Retention: 6 months (`prune:permit-observations`, like plate_lookups).
  Included in `plates:rekey` (one transaction per building with vehicles + sightings). PlateVault AD table name
  `permit_plate_observations`.
- `parking_exports`: add `include_apartments bool NOT NULL default false` + CHECK `kind = 'manager' OR NOT
  include_apartments`; allowed only when `settings.parking_rules_adopted_at` is set (policy + service).

### 6.2 PermitResource upgrade (register view)
Columns: permit number, type chip (Pamata / Viesu), apartment, stairwell, valid from–to ("beidzas pēc 12 d" warning),
status (icon + word), vehicle alias for main permits (masked `AB••34`, existing alias rule), observations count for
guest permits, source, flags (icons + words). Filters: type, status, stairwell, validity (active / expiring in 30 days /
expired), source, **"Dzīvokļi ar vairāk nekā 1 caurlaidi"** (apartments with > 1 active main permit, or > 2 active
permits in total — both counts shown), **"Viesu caurlaide vienmēr vienam auto"**, "Pamata caurlaide bez auto", "Auto
gaida apstiprinājumu", "Konflikts". Flags come from `PermitRegisterQuery` (one SQL per page, no decryption):
- `multi_main` — apartment has ≥ 2 active main permits.
- `guest_same_car` — guest permit with ≥ 3 observations on ≥ 3 distinct days spanning ≥ 14 days and exactly one distinct
  `plate_bidx` (thresholds in config `majas.parking.guest_same_car`).
- `main_without_vehicle`, `vehicle_pending`, `conflict` (existing officer-task kinds).
Actions: existing (edit validity, return, revoke, replace), **"Atzīmēt redzēto auto"** on guest permits (plate input
as action argument, sealed, consumes nothing from the resident lookup quota, officer/admin only, audited
`permit.observe`), Reveal (existing RevealService, cap 10/day). Bulk: "Pagarināt līdz…" (officer/admin, mfa).
Plates never appear unmasked in tables, filters, flags, exports for the manager, or import reports.

### 6.3 Import (PARK-012) — replaces the paste-only PARK-001.2 flow
Pipeline §4 with kind `permits`. Upload / mapping / validation / commit all require **parking L1**; below L1 the page
renders the explanation card instead of the uploader: "Caurlaižu sarakstu varēs ielādēt, kad māja būs noslēgusi līgumu ar
CoreDigify par stāvvietas datiem (L1). Trūkst: …" + button "Atvērt vedni". Rows: main permit → plate required →
`VehicleRegistry` creates/finds the vehicle (source `seed_list`, status `active`, conflict → row error); guest permit →
plate optional → becomes an observation (source `import`, `observed_on` = import date). Mode `create_or_update` updates
validity / status / issued_by of an existing active permit with the same number + type in the same apartment. Report
lists row numbers + codes only (existing rule). The issuer-endorsement checkbox stays mandatory.

### 6.4 Export (PARK-013)
Existing ParkingExportResource (four-eyes, signed 10 min, never stored) gains **"Caurlaižu saraksts"** columns:
- `manager` kind: permit number, type, valid from / to, status, stairwell; apartment numbers only with
  `include_apartments` (house rules adopted). No plates.
- `police` kind (only enforcement mode `plate_list|both`): plate (decrypted at generation), permit number, type, valid to.
  No apartment numbers. Unchanged approval rules.
CSV (UTF-8 BOM, `;`, formula-safe) + A4 PDF; header block (building, controller, PE number, generated at, roles).

### 6.5 Gate behaviour summary
| Level | Officer / admin see | Import | Export | Residents |
|---|---|---|---|---|
| L0 | Stāvvietas statuss, Zīme | explanation card, no upload | ✖ | nothing |
| L1 | full register, analytics, tasks | ✔ | ✔ | nothing (banner in admin: "iedzīvotāji vēl neredz") |
| L2 + module | as L1 | ✔ | ✔ | lookup, Manas caurlaides, declarations, sightings |

---

## 7. Migrations (ordered; all as `majas_owner`, grants in the same migration, GrantsCoverageTest extended)
(The `/admin` move is routing only — no migration.)
1. `2026_10_22_000110_add_roster_scope_to_notices` — alter CHECKs of `building_privacy_notices.covers_modules` and
   `privacy_notice_versions.covers_modules` (+ `roster`); add `document_id`, `body_override` to building notices.
2. `2026_10_22_000120_create_role_invitations_table` (+ section_ids trigger reuse; `login_tokens.purpose` CHECK gains `team_invite`).
3. `2026_10_22_000130_create_document_tables` — `document_templates` (+ published-body immutability trigger),
   `building_documents`.
4. `2026_10_22_000140_create_import_tables` — `import_runs`, `import_mapping_presets`.
5. `2026_10_22_000150_create_resident_contacts_table` (+ GIN on phone_bidx).
6. `2026_10_22_000160_permit_register_changes` — `permits.source`, `permits.import_run_id`, indexes,
   `permit_plate_observations` (+ guest-only trigger), `parking_exports.include_apartments` + CHECK.
7. `2026_10_22_000170_retention_grants_round2` — `majas_retention` DELETE on role_invitations, import_runs,
   resident_contacts, permit_plate_observations; SELECT where pruning needs it.
Every migration is reversible in dev; production rollback = previous image + `down()` for 1–7 (no data loss
before the features are used).

## 8. New / changed classes (contract names)
- Routing: `Admin\AdminEntryController`, `Http\Controllers\LegacyPanelRedirect`, `Livewire\Team\AcceptInvite`,
  `Http\Controllers\ImportTemplateController`.
- Panels: `ManagePanelProvider` (path `admin/b`, nav groups, brand), `PlatformPanelProvider` (path `admin/platform`).
- Team: `Services\Team\TeamInvitationService`, `Models\RoleInvitation`, `Policies\RoleInvitationPolicy`,
  `Filament\Manage\Pages\TeamPage`, `Notifications\TeamInvitation`, `Notifications\TeamMemberJoined`,
  `Console\Commands\Prune\PruneRoleInvitations`.
- Opening: `Filament\Manage\Pages\HouseOpeningWizardPage`, `Livewire\Admin\DocumentEditor`, `Gdpr\RosterGate`,
  `Gdpr\RosterGateStatus`, `Gdpr\RosterGateMonitor`, `Gdpr\GuardedByRosterGate`, `Gdpr\RetentionTable`,
  `Services\Documents\DocumentService`, `Services\Documents\TemplateLinter`, `Services\Documents\DocumentRenderer`,
  `Models\DocumentTemplate`, `Models\BuildingDocument`, `Policies\BuildingDocumentPolicy`,
  `Filament\Platform\Resources\DocumentTemplateResource`, `Filament\Platform\Pages\OpeningRequestsPage`,
  `PlatformPurpose::OpeningReview`, `Filament\Manage\Widgets\GateStatusWidget`, `Filament\Manage\Widgets\TeamWidget`,
  `Filament\Manage\Pages\ParkingStatusPage`, `database/seeders/DocumentTemplateSeeder` + `database/templates/*.md`.
- Import: `Services\Import\{CsvReader, ColumnMapper, RowValidator, ImportHandler (interface), ImportRunService,
  ImportVault}`, `Jobs\CommitImport` (TenantAware), `Models\ImportRun`, `Models\ImportMappingPreset`,
  `Policies\ImportRunPolicy`, `Support\PhoneNormalizer`, `Console\Commands\Prune\PruneImports`.
- Roster: `Models\ResidentContact`, `Services\Roster\{ContactVault, RosterService, RosterViewer, RosterExporter,
  RosterLinker, RosterImportHandler, ContactRekeyService}`, `Policies\ResidentContactPolicy`,
  `Filament\Manage\Resources\ResidentContactResource`, `Filament\Manage\Pages\RosterImportPage`,
  `Console\Commands\RekeyContacts` (`contacts:rekey`), `Console\Commands\Prune\PruneRoster`.
- Parking: `Services\Parking\{PermitRegisterQuery, PermitImportHandler, PermitObservationService}`,
  `Models\PermitPlateObservation`, PermitResource / ParkingExportService / PlateRekeyService changes,
  `PermitImportPage` rebuilt, `Console\Commands\Prune\PrunePermitObservations`.
- Changed: `LoginTokenPurpose` (+ `TeamInvite`), `MagicLinkService` (invite-bound codes), `BuildingSettings` (scope `roster`, `roster_informed_at`, `opening_completed_at`), `BuildingSettingsPage`
  (gate fields read-only), `BuildingRoleService::grant(…, ?RoleInvitation $via = null)` + last-admin guard,
  `GdprExporter`, `AccountEraser`, `RedactSecrets`, `ProductionGuard`, `GenerateKeys`, `Arch` allow-lists.

## 9. Security review notes (OWASP Top 10 2025)
- **A01** every new table tenant-scoped with composite FKs; `CrossTenantTest` dataset gains role_invitations,
  building_documents, import_runs, resident_contacts, permit_plate_observations (non-member 404, other-building id 404);
  elder section scoping tested on roster like heating; Filament `Select`s scoped; global search off for
  ResidentContactResource and PermitResource; `#[Locked]` ids; no PII in Livewire snapshots (test inspects
  `wire:snapshot` like PlateLookupSmokeTest). No new `withoutBuildingScope()` and no new `set()/runFor()` caller —
  accept-invite runs in the `tenant` middleware context; OpeningRequestsPage uses `PlatformBuildingContext` (new purpose
  only).
- **A02** Markdown rendered with `html_input: strip`; no inline scripts added to resident pages; Filament CSP debt
  unchanged.
- **A04** two new keys, escrow, rotation command; AEAD binds building/table/row/field.
- **A05/A08** CSV: size / row / column caps, finfo check, never executed, formula escaping on export, encoding
  normalisation, no external fetch of any URL (no "import from Google Sheets link" feature, ever).
- **A07** team tokens 32 random bytes, hashed, 7 days, single use, fragment only, e-mail-bound, throttled, neutral
  errors; account creation only after the e-mail code; strong step-up to invite; last-admin guard.
- **A09** audit actions: `team.invite.create|resend|revoke|accept`, `role.*` (existing), `opening.*`, `document.*`,
  `gate.roster.*`, `import.upload|commit|discard`, `roster.list|reveal|export|subject_export|create|update|delete|link|
  erase`, `permit.observe`, `permit.import` (counts only). AlertPayloadTest: roster/import alerts carry counts only.
- **Abuse limits:** reveals 100/day/user, roster export 5/day/building, invitations 20/day/building, imports 10/day/
  building + kind, accept page 10 tries / 15 min per device.

## 10. Design (DESIGN-SYSTEM §1–§6.10 apply)
Filament theme tokens unchanged; status chips always icon + word; 320 px reflow for every new page (TeamPage,
wizard cards, import stepper, roster cards); 48 px targets; senior mode respected in the resident shell (`/admin` entry,
accept page). Wizard card = title, status chip, 1–2 line description, "Kāpēc?" disclosure, "Kas to dara?" line,
one primary action. Import stepper = 5 labelled dots + "Solis 3 no 5". Copy: calm, no legal jargon in headings
("Līgums ar CoreDigify", not "Art. 28 DPA"; the legal name appears in the body). RU strings go to the native-review
list (UI-001). Never "svešs"; never names of residents outside the roster resource.

## 11. Acceptance criteria
- **AC-R2-01** `/admin` with one manage role → 302 to `/admin/b/{slug}`; with two buildings → chooser; platform_admin →
  chooser incl. "Platforma"; no role → `/home` with the message.
- **AC-R2-02** Every old `/manage/…` and `/platform/…` GET URL answers 301 to the same resource under `/admin/…` with the
  query string kept; panel ids, route names and policies unchanged; all existing Filament tests pass after a path update.
- **AC-R2-03** Building panel nav matches §1.2 per role; below L1 the Stāvvieta group shows "Stāvvietas statuss" + "Zīme"
  for admin/officer and nothing parking-related for elders.
- **AC-R2-04** Admin invites an e-mail as section_elder of stairwell 5 (strong step-up); the invitee without an account
  opens the mail link, confirms with the e-mail code, confirms 18+ and the account notice, accepts, and holds exactly that
  role; inviter and other admins are notified; audit chain verifies.
- **AC-R2-05** A token used with a different signed-in e-mail, after revoke, after resend (old token), after 7 days, or
  for another building's slug → identical neutral message; throttled after 10 attempts.
- **AC-R2-06** Revoking the last building_admin is refused; self-handover works after the new admin accepted.
- **AC-R2-07** Dashboard shows the three gate cards with the exact missing items; each "Turpināt" opens the matching
  wizard step.
- **AC-R2-08** With an empty building the wizard leads to L-core open: controller saved → DPA generated, linted,
  finalised, sent → platform admin records it from "Atvēršanas pieprasījumi" → notice published → `/join` accepts codes.
  Each step audited; CoreGateMonitor fires on opening.
- **AC-R2-09** Editing a locked block, removing a required marker, adding raw HTML or a phone-shaped string blocks
  finalising with a per-block error, LV and RU.
- **AC-R2-10** RosterGate closed → roster resource shows the closed card, upload refused (server-side, even with a
  forged Livewire call), model guard throws on save; opening L-R enables it; closing again hides data and alerts.
- **AC-R2-11** Roster import of a synthetic Google Sheets CSV (`,`, UTF-8) and an Excel LV CSV (`;`, BOM, and a
  Windows-1257 variant) maps headers automatically, previews masked values, flags `apartment_not_found`,
  `phone_invalid`, `duplicate_in_file`, dry-run counts equal the committed counts, and a second upload of the same file
  is detected.
- **AC-R2-12** Phones and e-mails are stored only as ciphertext + bidx (DB inspection test); masked in lists; reveal needs
  mfa, is audited with linked subject ids, and stops at the daily cap.
- **AC-R2-13** An elder sees and edits only contacts of own stairwell(s); 404 for others; parking_officer and
  volunteer get 403/404 on every roster URL; residents never see roster data anywhere.
- **AC-R2-14** GDPR: a linked resident's self-service export contains their contact rows; account erasure deletes them
  and notifies the admin with a count; "Kas skatīja manus datus" lists roster reveals; building export contains no roster.
- **AC-R2-15** Permit register filters "> 1 caurlaide" and "Viesu caurlaide vienmēr vienam auto" return the expected
  synthetic permits; no plate is ever rendered unmasked in the register, filters or import report.
- **AC-R2-16** Permit import below L1: no uploader, explanation card with missing items, forged upload refused; at L1 the
  pipeline imports main permits (vehicles `seed_list`) and guest observations; conflicts reported by row number + code.
- **AC-R2-17** Manager export without apartment numbers by default; `include_apartments` only after
  `parking_rules_adopted_at`; police export only in plate-list modes; four-eyes and 10-min signed links unchanged.
- **AC-R2-18** All new strings exist in LV and RU (parity test), pages reflow at 320 px and 200 % text without sideways
  scroll, axe-core: no serious/critical issues.
- **AC-R2-19** Import payloads are deleted at commit / discard / 24 h; no cell value appears in logs, GlitchTip
  breadcrumbs, audit `data` or exception messages (log capture test).
- **AC-R2-20** Personal-data guard passes: no phone-shaped string in tracked templates, seeds, tests or docs.

## 12. Tests required
**Pest feature / unit** (real PostgreSQL, synthetic data only — phones generated at runtime, never literal):
`Feature/Admin/AdminEntryTest`, `Feature/Admin/LegacyRedirectTest` (dataset of every old resource/page path + query),
`Feature/Admin/NavigationVisibilityTest` (role × gate level), `Feature/Team/TeamInvitationTest` (create quota, duplicates,
self, strong step-up), `Feature/Team/AcceptInviteTest` (new account, existing account, wrong e-mail, expired, revoked,
resent, other building, throttle, identical responses), `Feature/Team/LastAdminGuardTest`, `Unit/BuildingRoleServiceViaInvitationTest`
(strong-check bypass only with a valid invitation), `Feature/Opening/WizardFlowTest` (AC-R2-08), `Feature/Opening/
OpeningRequestsTest` (platform record + PlatformPurpose audit), `Unit/TemplateLinterTest`, `Feature/Opening/
DocumentRenderTest` (Markdown sanitising, PDF DejaVu LV/RU), `Feature/Gdpr/RosterGateTest` (policy, model guard, UI,
monitor), `Feature/Gdpr/RetentionTableTest` (notice table = prune config), `Unit/CsvReaderTest` (BOM, encodings,
delimiters, quoting, multi-line cells, caps), `Unit/ColumnMapperTest` (LV/RU/EN synonyms, presets), `Unit/
PhoneNormalizerTest`, `Unit/RowValidatorRosterTest`, `Unit/RowValidatorPermitTest`, `Feature/Import/ImportPipelineTest`
(stages, dry-run = commit counts, re-validation on commit, idempotent job, concurrent commit lock, same-file detection,
payload deletion), `Feature/Import/ImportPrivacyTest` (no values in snapshot / logs / audit / exceptions),
`Unit/ContactVaultTest` (AD binding per field/row/building, rotation), `Feature/Roster/RekeyContactsTest`,
`Feature/Roster/RosterVisibilityTest` (admin / elder own / elder other / officer / volunteer / resident / platform),
`Feature/Roster/RevealAuditTest` (cap, subject ids, WhoViewed), `Feature/Roster/RosterExportTest` (strong step-up,
formula-safe, not stored), `Feature/Roster/RosterLinkerTest`, `Feature/Gdpr/GdprTest` extended (export + erasure of
contacts), `Feature/Gdpr/BuildingExportExcludesRosterTest`, `Feature/Parking/PermitRegisterQueryTest` (flags),
`Feature/Parking/PermitObservationTest` (guest only, sealed, retention, rekey), `Feature/Parking/PermitImportPipelineTest`
(L0 refusal, L1 import, conflicts, update mode), `Feature/Parking/ExportIncludeApartmentsTest`, `Tenancy/CrossTenantTest`
+ `Db/GrantsCoverageTest` + `Security/AlertPayloadTest` + `I18n` parity extended, `Arch/TenancyArchTest` (no new scope
lifts / context setters), `Arch/VaultCallersTest` (ContactVault::open allow-list).
**Browser** (pest-plugin-browser + Chromium, `scripts/dev.sh browser`): `Browser/AdminEntrySmokeTest` (chooser,
switcher, old URL redirect), `Browser/TeamInviteSmokeTest` (invite → Mailpit → fragment link → e-mail code → accept →
passkey offer with the virtual authenticator), `Browser/OpeningWizardSmokeTest` (empty house to L-core open with the
platform admin in a second context; DPA editor locked/editable blocks; 320 px), `Browser/RosterImportSmokeTest`
(upload synthetic CSV, mapping, preview masking, dry-run, commit with progress, reveal modal, `wire:snapshot` free of
phones), `Browser/PermitImportSmokeTest` (L0 explanation card, then L1 import and register filters), `Browser/LayoutTest`
extended with the new pages (LV/RU × normal/senior × 100/200 % at 320 px, axe-core).

## 13. Build order and task mapping (BRAIN/TASK.md "Kārta 2")
1. ADMIN-005 (paths, entry, redirects, nav) — small, unblocks everything visible.
2. ADMIN-006 (Komanda) — the owner can hand over work immediately.
3. GOV-012 + GOV-011 + GOV-010 + UI-011 (gates, templates, wizard, dashboard) — makes the closed gates explainable.
4. DB-003 (import pipeline) with PARK-012 as its first consumer (smaller data, existing services).
5. SEC-011 + ADMIN-007 (ContactVault, roster) — after the pipeline is proven.
6. PARK-011 + PARK-013 (register flags, observations, export).
7. TEST-004 browser suite + Karen round-2 gate, then deploy.
Versioning: patch bump per commit (global rule). The owner may approve **0.1.0** for the round-2 release
(MANUAL-TODO) — minor bumps need approval.

## 14. Human tasks / open questions (→ MANUAL-TODO)
- Approve D1 (admin-only roster with names/phones) explicitly — it reverses an MVP privacy rule.
- Send the Google Sheet and permit list **column headers only** (no data) so the synonym tables can be checked.
- Legal review of the DPA / notice / DPIA-lite masters and native RU review of all new strings.
- Who signs the DPA for Pūces iela 51 (controller person / kopība) and the scope (recommend core, heating, outages,
  parking, roster at once).
- Generate and escrow `CONTACT_ENC_KEY` and `CONTACT_INDEX_KEY` before the first roster import (Coolify env +
  password manager).
- Approve the version jump to 0.1.0 for the round-2 release (optional).

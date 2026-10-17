# MANUAL-TODO — tasks only a human can do

> Revised 2026-10-17 (rev. 3 — Karen gate 2: core gate L-core, DB bootstrap, sign-offs). Blocking items are marked 🎯
> with what they block.

## Izlemts (2026-10-17)
- ✅ Tehnoloģiju izvēle: Laravel + Livewire + Filament — COMPLETED (PLANNING §3).
- ✅ Pilota apjoms: visa māja Pūces iela 51 (180 dz.), aktīvais vilnis 1 = 5. kāpņu telpa — COMPLETED.
- ✅ Darba domēns: apakšdomēns zem coredigify.com (`kaimini.coredigify.com`) — COMPLETED.

## Jāapstiprina īpašniekam
- 🎯 Apstiprināt būvēšanas secību **A → B (siltums) → C (stāvvieta)**. Sākotnējā uzdevumā stāvvieta bija 1. prioritāte;
  ieteikums mainīt, jo apkures sezona ir sākusies, stāvvietai pirms jebkura numura glabāšanas vajag parakstītu DPA
  (vārti L1), un kopš 2026-06 policijai numuru saraksts vairs nav vajadzīgs. Ja paliek "stāvvieta pirmā" — mainām
  1a/1b vietām (Pakete A nemainās). **Nepieciešams skaidrs "jā/nē" pirms Paketes B sākuma** (Pakete A turpinās
  jebkurā gadījumā). Blocks: start of Package B (phase 1 ordering).
- 🎯 Izvēlēties un pārbaudīt **savu domēnu** (Openprovider 2026-10-17: brīvi kaiminos.lv, kaimi.lv, kaiminu.lv; kopiba.lv un kaimini.lv AIZŅEMTI) un to reģistrēt
  **pirms uzaicinājuma vēstuļu drukas** — drukāto URL vairs nevar mainīt. Blocks: AUTH-002.1 print run.
  Īpašnieka saraksts: kopiba.lv (aizņemts) / stavads.lv (nav pārbaudīts) / kaiminu.lv (brīvs).
  **Arhitekta priekšlikums** (nav īpašnieka sarakstā, pieņemt vai noraidīt): majaspaligs.lv (nosaukums = adrese;
  pieejamība nav pārbaudīta) — skat. BUSINESS §6.

## Juridiskais (bloķē vilni 1 un stāvvietu)
- 🎯 **Pamata vārti L-core — bloķē vilni 1.** Noteikt **datu pārzini** pilotam (mājas pilnvarotā persona, kopība vai
  biedrība: vārds/nosaukums + kontakts), parakstīt **DPA** ar CoreDigify kā apstrādātāju ar tvērumu
  `core, heating, outages` (ieteicams uzreiz arī `parking`) un **publicēt mājas privātuma paziņojumu LV/RU**, kas
  aptver core/heating/outages. CoreDigify pati ir pārzinis tikai platformas kontiem (e-pasts, PIN, drošības žurnāli) —
  tam vajag publicētu platformas konta paziņojumu. Līdz tam `/join` un uzaicinājuma vēstuļu druka ir bloķēti
  (PLANNING §5, GOV-008, `Feature/Gdpr/CoreGateTest`). Blocks: **wave 1 launch**, AUTH-002.1 print run, any membership.
- 🎯 DPA tvērumā iekļaut `parking`. Blocks: parking gate **L1** — any plate/permit storage incl. the permit-list
  import (PARK-001.2).
- 🎯 Pabeigt **DPIA-lite** stāvvietas modulim, publicēt privātuma paziņojumu LV/RU ar stāvvietas sadaļu, ierakstīt mājas
  lēmuma / pilnvarojuma atsauci (`mandate_ref`). Blocks: parking gate **L2** (resident-facing parking).
- 🆕 Mājas lēmums par stāvvietas noteikumiem (`parking_rules_adopted_at`) — blocks per-apartment parking analytics.
- 🆕 Mājas lēmums, vai pārvaldniekam drīkst kopīgot stāvus / dzīvokļu numurus siltuma pārskatā (`share_with_manager`).

## Cilvēki
- 🎯 Iecelt **building_admin** pilotam un **kāpņu telpu vecākos** (vismaz 5. k.t. pirms viļņa 1; 1.–4. k.t. viļņiem 2–3).
  Blocks: membership approvals, PIN resets, wave 1 launch.
- 🆕 Sarunāt ar caurlaižu izsniedzēju par sadarbību un esošo sarakstu (imports tikai pēc L1).
- 🆕 Atrast **dzimtās krievu valodas recenzentu** UI tekstiem. Blocks: wave 1 launch (RU strings).
- 🆕 RNP kontakts siltuma pārskata formātam (pirmo pārskatu prezentēt klātienē).

## Mājas dati
- 🆕 Noskaidrot **istabu sastāvu (layout preset) katrai kāpņu telpai** (1-/2-/3-istabu sadalījums pa pozīcijām) un vai ir
  apvienoti dzīvokļi. Blocks: accurate PucesIela51Seeder (SERIES-003); until then preset 2-2-3-3 = hypothesis.
- 🆕 Iegūt mājas plānus no BIS mājas lietas (stāvadu izvietojums 602. sērijai) — paaugstina stāvadu `confidence`.
- 🆕 Otrās zemes vienības kadastra numurs (k.t. 1–3).
- 🆕 Pārbaudīt Ceļu satiksmes likuma 19. panta (6) daļas spēkā stāšanās datumu likumi.lv.

## Infrastruktūra
- 🎯 Izveidot Coolify Docker Compose resursu + PostgreSQL 18 + Redis; kā Postgres superuser palaist `database/sql/roles.sql`
  (lomas `majas_owner`, `majas_app`, `majas_retention` ar atsevišķām parolēm; `CREATE DATABASE majas OWNER majas_owner`;
  `ALTER SCHEMA public OWNER TO majas_owner`; paplašinājumi `btree_gist`, `citext`, `pg_trgm` kā superuser) un ielikt
  paroles tikai attiecīgajos servisos. Blocks: first production `migrate` (MVP-SPEC A.2a).
- 🆕 Iestatīt žurnālu glabāšanu: Coolify/Docker piekļuves žurnāli 14 dienas, GlitchTip notikumi 30 dienas (PLANNING §5).
- 🆕 E-pasta sūtītājs (ES transakciju pakalpojums) + SPF/DKIM/DMARC `p=reject` sūtīšanas apakšdomēnam.
- 🆕 Atslēgu (APP_KEY, PLATE_ENC_KEY, PLATE_INDEX_KEY, INVITE_KEY) bezsaistes glabāšana (escrow).

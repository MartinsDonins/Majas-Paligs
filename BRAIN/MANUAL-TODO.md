# MANUAL-TODO — tasks only a human can do

> Revised 2026-10-18 (rev. 4 — review fixes: governance drift corrected, deploy / security human tasks added).
> Blocking items are marked 🎯 with what they block.

## Izlemts (īpašnieka vārdiem, 2026-10-17)
- ✅ Tehnoloģiju izvēle: Laravel + Livewire + Filament ("apstiprinu laravel") — COMPLETED (PLANNING §3).
- ✅ Pilota apjoms: visa māja Pūces iela 51, 180 dz. ("pilots visai mājai") — COMPLETED.
- ✅ Darba domēns: kāds apakšdomēns zem coredigify.com — COMPLETED (konkrētais nosaukums vēl jāapstiprina, skat. zemāk).
- ❌ Būvēšanas secības A → B → C apstiprinājums — TO DELETE (superseded): Paketes B un C jau uzbūvētas bez šī
  ierakstītā "jā/nē"; jautājums vairs nav aktuāls (PLANNING §6 piezīme).

## Jāapstiprina īpašniekam
- 🆕 **Arhitekta priekšlikums:** aktīvais vilnis 1 = tikai 5. kāpņu telpa, viļņi 2–3 = pārējās, kad tām ir vecākais.
  Īpašnieks teica tikai "pilots visai mājai" — apstipriniet vai izvēlieties visas kāpņu telpas uzreiz.
- 🆕 **Arhitekta priekšlikums:** darba adrese `kaimini.coredigify.com`. Nosaukums neatbilst produktam "Mājas Palīgs";
  alternatīva `majaspaligs.coredigify.com`. Maina tikai `APP_URL`. Blocks: first deploy URL.
- 🆕 Uzaicinājuma vēstule tagad ir **A4** (salokāma DL aploksnē / pastkastītei), viena lapa katrā valodā — A5 ar
  ≥ 16 pt burtiem neietilpa (arī vecā 12 pt A5 versija jau pārplūda uz otru lapu). Apstipriniet A4 vai prasiet
  īsāku tekstu A5 formātam.
- 🎯 Izvēlēties un pārbaudīt **savu domēnu** (Openprovider 2026-10-17: brīvi kaiminos.lv, kaimi.lv, kaiminu.lv; kopiba.lv un kaimini.lv AIZŅEMTI) un to reģistrēt
  **pirms uzaicinājuma vēstuļu drukas** — drukāto URL vairs nevar mainīt. Blocks: AUTH-002.1 print run.
  Īpašnieka saraksts: kopiba.lv (aizņemts) / stavads.lv (nav pārbaudīts) / kaiminu.lv (brīvs).
  **Arhitekta priekšlikums** (nav īpašnieka sarakstā, pieņemt vai noraidīt): majaspaligs.lv (nosaukums = adrese;
  pieejamība nav pārbaudīta) — skat. BUSINESS §6.

## Kārta 2 (BRAIN/docs/ROUND2-SPEC.md §14, 2026-10-21)
- 🎯 **Apstiprināt lēmumu D1:** iedzīvotāju saraksts ar vārdiem, telefoniem un e-pastiem, ko redz tikai mājas
  administrators un attiecīgās kāpņu telpas vecākais. Tas maina iepriekšējo noteikumu "MVP nevāc vārdus un telefonus".
  Iedzīvotājiem saraksts netiek rādīts. Blocks: ADMIN-007.
- 🆕 Atsūtīt Google tabulas un caurlaižu saraksta **tikai kolonnu nosaukumus** (bez datiem), lai pārbaudītu automātisko
  kolonnu atpazīšanu (DB-003).
- 🆕 Juridiski pārbaudīt DPA, privātuma paziņojuma un DPIA-lite paraugus, kā arī nodot dzimtās krievu valodas recenzentam
  visus jaunos tekstus (GOV-011, UI-001).
- 🎯 Izlemt, **kas paraksta DPA** Pūces ielai 51 (pārzinis) un kādā tvērumā. Ieteikums: uzreiz core, heating, outages,
  parking un roster. Blocks: L-core, L-R, parking L1.
- 🎯 Pirms pirmā saraksta importa izveidot un noglabāt (Coolify vidē un paroļu pārvaldniekā) **`CONTACT_ENC_KEY`** un
  **`CONTACT_INDEX_KEY`**. Blocks: ADMIN-007 production use. Izveide: `php artisan majas:keys` (izdrukā abas); ievadīt
  Coolify kopīgajā vidē — `docker/compose.prod.yaml` tās jau padod konteineriem. Atslēgu nedrīkst ņemt no PLATE_* (sistēma
  atteiksies startēt).
- 🆕 (Pēc izvēles) apstiprināt versiju **0.1.0** kārtas 2 izlaidumam (minor versija tikai ar apstiprinājumu).

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
- 🎯 Atrast **dzimtās krievu valodas recenzentu** UI tekstiem (0.0.14 izlaboti daudzskaitļi un stāvokļu formas, bet
  dzimtās valodas pārbaude nav notikusi). Blocks: wave 1 launch (RU strings), UI-001 stays 🔍.
- 🆕 RNP kontakts siltuma pārskata formātam (pirmo pārskatu prezentēt klātienē).
- 🎯 **Reālas ierīces pārbaude (UI-002.1)** — automātiskā daļa gatava 0.0.16 (Chromium testi, Lighthouse a11y 100,
  320px / 200 %). Cilvēkam: lēts Android telefons (Chrome + TalkBack) un iPhone (Safari + VoiceOver) — pieteikšanās,
  ziņojums par siltumu, numura pārbaude, **HEIC foto ar pogu «Fotografēt» uz iPhone**; viena ielūguma vēstule
  izdrukāta A4. Blocks: wave 1 launch.

## Mājas dati
- 🆕 Noskaidrot **istabu sastāvu (layout preset) katrai kāpņu telpai** (1-/2-/3-istabu sadalījums pa pozīcijām) un vai ir
  apvienoti dzīvokļi. Blocks: accurate PucesIela51Seeder (SERIES-003); until then preset 2-2-3-3 = hypothesis.
- 🆕 Iegūt mājas plānus no BIS mājas lietas (stāvadu izvietojums 602. sērijai) — paaugstina stāvadu `confidence`.
  Interneta meklēšana 2026-10-18: publisku plānu šai adresei un 602. sērijas stāvadu rasējumu nav (PLANNING §8);
  avoti — BIS mājas lieta, Rīgas pilsētas arhitekta arhīvs, RNP.
- 🆕 Otrās zemes vienības kadastra numurs (k.t. 1–3).
- 🆕 Pārbaudīt Ceļu satiksmes likuma 19. panta (6) daļas spēkā stāšanās datumu likumi.lv.

## Izvietošana un drošība (review fixes 0.0.12–0.0.13)
- 🎯 **Push `main` uz GitHub** un sagaidīt pirmo zaļo CI (lokāli `main` ir priekšā origin; neviens CI vēl nav
  palaists). Pievienot `COOLIFY_WEBHOOK` / `COOLIFY_TOKEN` noslēpumus. Blocks: DEPLOY-001.5, DEPLOY-001.3, any deploy.
- 🎯 Coolify serverim pievienot **GHCR reģistra piekļuvi** (PAT ar `read:packages`), jo `ghcr.io/martinsdonins/majas-paligs`
  pakete ir privāta (ADR 0002). Blocks: first deploy.
- 🎯 Iestatīt **`REDIS_PASSWORD`** (Redis AUTH, tikai privātajā Coolify tīklā) un **`DB_SSLMODE=require`** — bez tiem
  aplikācija ražošanā neieslēdzas (ProductionGuard). Blocks: first deploy.
- 🎯 Pēc 0.0.20 izvietošanas pārbaudīt `curl https://kaimini.coredigify.com/up/ip` no sava telefona/datora: jābūt
  SAVAM IP prefiksam, nevis `172.28.0.0/24` (tagad `TRUSTED_PROXY=local`, jo Cloudflare priekšā nav). Ja kādreiz
  ieslēdz Cloudflare starpniekserveri — pārslēgt uz `cloudflare` un atļaut 80/443 tikai no Cloudflare IP (docs/SECURITY.md).
- 🆕 Pilota building_admin un parking_officer: **izveidot ieejas atslēgu (pirksts / Face ID)** — no 0.0.23 to piedāvā
  uzreiz pēc e-pasta ieejas (ADR 0006); autentifikatora lietotne (TOTP) paliek kā rezerves variants.
- 🆕 Pēc 0.0.23 izvietošanas (īpašnieks): ieiet ar e-pasta kodu → izveidot ieejas atslēgu telefonā **un** datorā (vai
  paturēt TOTP kā rezervi); pārbaudīt, ka nākamā ieeja ir ar vienu pieskārienu. Pazaudētas visas ierīces →
  `php artisan majas:reset-mfa <e-pasts>` Coolify konsolē (tikai pēc personas pārbaudes).
- 🆕 DPA datus (datums, atsauce, tvērums) ieraksta **platformas panelī** (CoreDigify, platform_admin + TOTP), nevis mājas
  administrators — pēc DPA parakstīšanas to izdara CoreDigify.

## Infrastruktūra
- 🎯 Izveidot Coolify Docker Compose resursu + PostgreSQL 18 + Redis; kā Postgres superuser palaist `database/sql/roles.sql`
  (lomas `majas_owner`, `majas_app`, `majas_retention` ar atsevišķām parolēm; `CREATE DATABASE majas OWNER majas_owner`;
  `ALTER SCHEMA public OWNER TO majas_owner`; paplašinājumi `btree_gist`, `citext`, `pg_trgm` kā superuser) un ielikt
  paroles tikai attiecīgajos servisos. Blocks: first production `migrate` (MVP-SPEC A.2a).
- 🆕 Iestatīt žurnālu glabāšanu: Coolify/Docker piekļuves žurnāli 14 dienas, GlitchTip notikumi 30 dienas (PLANNING §5).
- 🆕 E-pasta sūtītājs (ES transakciju pakalpojums) + SPF/DKIM/DMARC `p=reject` sūtīšanas apakšdomēnam.
- 🆕 Atslēgu (APP_KEY, PLATE_ENC_KEY, PLATE_INDEX_KEY, INVITE_KEY) bezsaistes glabāšana (escrow).

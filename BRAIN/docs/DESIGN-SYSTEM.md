# DESIGN-SYSTEM — Mājas Palīgs

> Mobile-first PWA for all ages, Latvian + Russian. Stack: Tailwind CSS v4 (`@theme`), Blade components
> + Alpine, Livewire 4; Filament 5 admin themed from the same token file. Revision 2026-10-17.

## 1. Principles
1. **Answer two questions in one tap:** "Is it just me or the whole riser?" and "Is this car ours?"
2. **Aggregates, not people.** Residents see riser/column aggregates and their own apartment only. No names,
   avatars or photos of people anywhere in the resident UI. Apartments appear as "Dz. 1xx" only where the role permits.
3. **Never colour alone.** Every status = icon + word + (where needed) pattern. CVD-safe (Okabe-Ito).
4. **Calm, neutral language.** "Nav atrasts reģistrā / Нет в списке", never "svešs / чужая / нарушитель".
5. **Trust signals over polish:** "Kods no Jūsu pastkastītes", "Kas redz šo informāciju?", "Šī darbība tiek reģistrēta".
6. **Senior-proof:** large targets, visible labels, no hidden gestures, no disappearing confirmations, no CAPTCHA.
7. **Bilingual equality:** RU runs 15–25% longer — every component must wrap cleanly at 320px.

## 2. Tokens (`resources/css/tokens.css`, imported by `app.css` and both Filament themes)

### 2.1 Colour — brand & neutrals (light)
| Token | Value | Use |
|---|---|---|
| `--color-primary-700` | `#0B4F55` | primary text/buttons on white (~9:1) |
| `--color-primary-600` | `#0E6169` | hover |
| `--color-primary-500` | `#13808A` | focus ring, accents |
| `--color-primary-100` | `#D7EEEF` | active nav pill, selected chips |
| `--color-primary-50` | `#EEF7F7` | subtle backgrounds |
| `--color-bg` | `#F7F6F3` | page background (warm stone) |
| `--color-surface` | `#FFFFFF` | cards |
| `--color-surface-2` | `#F0EEE9` | inset / "no data" |
| `--color-line` | `#DAD6CE` | borders |
| `--color-fg` | `#1C1A17` | body text |
| `--color-fg-muted` | `#57534E` | secondary text (7.1:1) |
| `--color-success` | `#1E7A46` | registered, done |
| `--color-warning` | `#A15C00` | expiring soon (text-safe amber) |
| `--color-danger` | `#B42318` | errors, destructive only |
| `--color-info` | `#0B4F55` | info |

Petrol is deliberately not blue so it never collides with the "cold" heating colour.
Components use only semantic aliases: `--color-bg`, `--color-surface`, `--color-fg`, `--color-fg-muted`,
`--color-line`, `--color-accent`.

### 2.2 Dark mode
`@custom-variant dark (&:where([data-theme=dark], [data-theme=dark] *));` plus `prefers-color-scheme` default;
3-way switch Sistēma / Gaišs / Tumšs in "Mans dzīvoklis".
| Token | Dark value |
|---|---|
| bg | `#141312` |
| surface | `#1E1C1A` |
| surface-2 | `#2A2724` |
| line | `#3A3632` |
| fg | `#F2EFEA` |
| fg-muted | `#B5AFA6` |
| primary (links/buttons) | `#4FB3BC` with dark text on primary buttons |
Heating fills keep their hue plus a 1px `rgba(255,255,255,.18)` inner border; lukewarm drops to `#D9CD3A`.
Dark mode uses borders instead of shadows.

### 2.3 Heating & outage status palette
| State | Fill | Ink | Icon | LV / RU label | Pattern |
|---|---|---|---|---|---|
| cold | `#0072B2` | white | snowflake | Auksts / Холодно | solid |
| lukewarm | `#F0E442` | `#1C1A17` | thermometer-low | Remdens / Едва тёплый | solid |
| warm | `#E69F00` | `#1C1A17` | thermometer | Silts / Тепло | solid |
| overheated | `#A3195B` | white | flame + arrow-up | Pārkurināts / Перегрев | 45° hatch |
| unconfirmed (1 apartment) | state colour as 3px outline on surface | fg | state icon + "?" | Neapstiprināts / Не подтверждено | outline |
| no data | surface-2 + dashed `#A8A29E` | fg-muted | dotted circle | Nav datu / Нет данных | dotted |
| mixed / conflicting | diagonal split of two states | — | "!" badge | Pretrunīgi / Противоречиво | split |
| checked | state + small check badge | — | check | Pārbaudīts / Проверено | — |
| disputed | surface-2 + amber border | fg | help-circle | Pārbaudāms / Требует проверки | — |

Luminance order (dark cold > light lukewarm > mid warm > dark+hatch overheated) keeps states distinct in
greyscale and in print. CI screenshot check with deuteranopia/protanopia simulation.

**Outage overlay** (separate mode, never mixed with heating): hatched petrol overlay + icons droplet-slash
"Nav aukstā ūdens", red droplet-slash "Nav karstā ūdens", radiator-slash "Nav siltuma".

### 2.4 Typography
- **Inter Variable**, self-hosted via `@fontsource-variable/inter` bundled by Vite (Latin Extended-A + Cyrillic;
  zero third-party requests). Never Google Fonts. Fallback stack `system-ui, sans-serif`.
- Apartment numbers, permit numbers, plates, codes: `font-feature-settings: "tnum" 1, "ss01" 1`. Plate and
  invite-code inputs: uppercase, `letter-spacing: .08em`, Inter tnum (or JetBrains Mono self-hosted).
- Scale (root 16px): `xs` 13px (meta/legend only, never actions) · `sm` 15px · **`base` 17px / 1.55** · `lg` 20px ·
  `xl` 24px · `2xl` 30px (page titles) · `3xl` 36px (lookup result). Weights 400/500/600/700; no light weights.
- **Senior mode** ("Lielais teksts / Крупный шрифт"): `html[data-density=large]` → base 19–22px, extra spacing,
  simplified Home. Toggle reachable before login. System font scaling up to 200% must not break layout.

### 2.5 Spacing, radius, shadow, motion
- 4px base grid (Tailwind default). Page gutter 16px; card padding 16px (20px ≥640px); section gap 24px.
- Radius: `--radius-sm` 8px (chips, inputs), `--radius-md` 12px (cards), grid cells 6px, `--radius-lg` 20px (sheet
  top corners), full for pills/FAB.
- Shadows: `--shadow-1: 0 1px 2px rgb(28 26 23/.06), 0 1px 1px rgb(28 26 23/.04)` (cards);
  `--shadow-2: 0 8px 24px rgb(28 26 23/.12)` (sheets, toasts).
- Motion 150–220 ms ease-out. `prefers-reduced-motion`: no sheet spring, static dot instead of pulse, no riser fill animation.

### 2.6 Targets & accessibility (WCAG 2.2 AA, aiming AAA contrast for body text)
All targets ≥48×48 px (home tiles 96px, primary buttons 56px); grid cells ≥56px high; inputs 52px; text ≥4.5:1,
UI borders/icons/focus ≥3:1; focus ring 3px primary-500 + 2px offset in both themes; `scroll-padding-bottom` =
bottom nav height (SC 2.4.11); everything draggable also has buttons (2.5.7); help in the same place on every
screen (3.2.6); no CAPTCHA, paste allowed (3.3.8); never re-ask the apartment (3.3.7); `<html lang="lv|ru">`.
Test with TalkBack on a low-end Android and VoiceOver, in sunlight and in a dark stairwell.

## 3. Copy rules
- Resident UI avoids: "verifikācija", "autentifikācija", "profils", "lietotne" in headings; RU avoids "аккаунт",
  "авторизация", "верификация", "регистрация", "уведомления". Use: "Ieiet / Войти", "Mans dzīvoklis / Моя
  квартира", "Ziņojums / Сообщение", "Kods no pastkastītes / Код из почтового ящика".
- Residents' own words: "stāvads / стояк", "radiators / батарея", "kāpņu telpas vecākais / старший по подъезду",
  "caurlaide / пропуск", "RNP".
- Errors explain the fix: "Kods neder. Pārbaudiet burtus un ciparus. Var rakstīt arī mazos burtus." /
  "Код не подходит. Проверьте цифры и буквы. Можно писать маленькими буквами."
- Rate limits are calm: "Pagaidiet 10 minūtes un mēģiniet vēlreiz" / "Подождите 10 минут и попробуйте снова".
- Native RU speaker reviews every string; LV and RU keys must be identical (CI).

## 4. App shell
- **Top bar** 56px, sticky: building switcher pill "Pūces 51 · 5. k.t." (sheet lists the user's buildings/apartments),
  notification bell (Phase 2), language toggle "LV | RU" (text buttons, no flags).
- **Bottom nav** 64px + `env(safe-area-inset-bottom)`, 4 items with always-visible labels: **Sākums, Siltums,
  Stāvvieta, Vairāk** (Главная, Тепло, Парковка, Ещё). Active = primary-100 pill behind icon + 600 label.
  Items are **module-dependent**: "Stāvvieta" appears only when `modules.parking` is on (gate L2) — otherwise
  3 items (Sākums, Siltums, Vairāk), evenly spaced. "Siltums" hides if both heating and outages are off.
  No hamburger, no icon-only nav, never more than 5 items. ≥1024px → left rail.
- `wire:navigate` transitions; PWA manifest with `theme_color` per theme; offline banner "Nav savienojuma —
  mēģiniet vēlreiz, kad būsiet tiešsaistē" (no offline queue in the MVP, §7).
- Footer (in "Vairāk" and desktop): version, privacy notice, "Aplikācija nav avārijas kanāls" + manager emergency phone.

## 5. Components (`resources/views/components/ui/*`)
| Component | Spec |
|---|---|
| `card` | header / body / footer actions; shadow-1; radius-md |
| `stat-tile` | number (tnum, xl) + label + optional trend; used in elder/admin analytics |
| `status-chip` | icon + label, sizes sm/md; all heating/outage/permit states (Aktīva, Beidzas drīz ≤30 d, Beigusies, Gaida apstiprinājumu, Atsaukta) |
| `building-grid` | see §5.1 |
| `riser-band` | 8px tinted vertical strip behind a column, dashed when a riser serves only one room type |
| `bottom-sheet` | Alpine `x-trap` focus, Esc + swipe-down + visible "Aizvērt" button; max 90vh; radius-lg top |
| `segmented-control` | 2–4 large options with icon + label, 48px, wraps to 2×2 in RU |
| `field` | label always above, helper text, error under field with icon + `aria-describedby`; never placeholder-as-label |
| `plate-input` | 64px, mono/tnum, auto-uppercase, strips spaces/dashes, accepts Cyrillic look-alikes, `autocomplete=off` |
| `code-input` | grouped `XXXXX-XXXXX`, paste-friendly, Cyrillic-tolerant |
| `pin-pad` | 6 big digits, visible keypad, "Rādīt / Показать" toggle |
| `stepper` | °C ±0.5, buttons 48px |
| `big-action` | home tile 96px, icon 32px + 2-line label |
| `empty-state` | line illustration of a 602 facade + one sentence + one CTA |
| `toast` | bottom-centre above nav, `role=status` (assertive for errors), 6–8 s, undo when reversible, never the only error carrier |
| `banner` | outage / system / legal; dismissible only when non-critical |
| `privacy-note` | inline "Kas redz šo?" disclosure, lock icon |
| `me-too` | "Man arī / У меня тоже" one-tap confirm with count "Pieteikuši 5 dzīvokļi" |
| `progress-ring` | per-stairwell campaign completion (aggregate only) |
| `plate-badge` | Latvian plate look (white, thin black border, blue EU strip) — **only for the user's own vehicles**; lookups never echo a plate in this style |
| `list-row` | avatar-free; "Dz. 1xx" or "Mans dzīvoklis" |
Icons: Lucide (stroke 2, 20/24px) via blade-lucide-icons + custom radiator, riser, plate icons.

### 5.1 Building grid / riser map
- One stairwell = 4 columns × 9 rows, floor 9 on top; 32px left gutter with floor labels "9." … "1."; cells
  min 64×56px, 6px gap → fits 343px content width.
- Cell: apartment number (15px/600, tnum) + status icon (18px). My apartment: 3px primary ring + "Jūs" tag.
- **Resident view:** every cell of a column shows the same column/riser aggregate (band fill); individual
  reports, per-floor states and "which floors reported" are never shown (riser + floor = apartment).
  **Staff view:** per-apartment states for building_admin (all stairwells) and for a section_elder **only in
  their own stairwell**; other stairwells render the resident view for that elder.
- Vertical riser bands make the stack visible (e.g. 145 → 149 → … → 177).
- Column footer meter: "4/9 stāvi" coverage as a plain fill bar (count, not positions). Air tip: residents see a
  generic line on any cold/lukewarm riser ("Ja auksti tikai augšējos stāvos, bieži vainojams gaiss stāvadā —
  ziņojiet pārvaldniekam par atgaisošanu"); the specific "tikai augšējie stāvi" detection shows to staff only.
- Stairwell tabs 1–5 as horizontally scrollable segmented control; whole-building mini map (5 × 4 = 20 columns,
  12px cells), tap a stairwell to zoom.
- Toggle **Režģis / Saraksts**: list mode is the accessible equivalent ("Stāvads 5-K (kreisā puse): auksts 3,
  silts 2, nav datu 4 stāvi").
- `role="grid"` with arrow-key navigation; cell `aria-label` "Dzīvoklis 157, 4. stāvs, stāvads auksts, 2 ziņojumi".
- Server-renders once; tabs and sheets via Alpine; only saves go over the wire.

## 6. Screens

### 6.1 Onboarding (4 steps, progress dots)
1. **Language:** two 64px buttons "Latviešu" / "Русский"; "Lielais teksts" toggle visible.
2. **Code:** "Ievadiet kodu no pastkastītes vēstules" — one field, one 56px button "Turpināt". Link "Nav vēstules?
   Sazinieties ar kāpņu telpas vecāko" (anonymous request, no phone shown). Trust line: "Šis NAV BIS un NAV RNP.
   Paraksts un banka nav vajadzīgi."
3. **Confirm apartment:** small 602 illustration with the apartment highlighted: "Pūces iela 51 · 5. kāpņu telpa ·
   dzīvoklis 1xx · 3. stāvs"; above the role choice a short "Kas apstrādā datus" card (house controller name +
   "CoreDigify — konta drošība", two links to the full LV/RU notices); role: Īpašnieks / Īrnieks / Ģimenes loceklis.
   If the house is not yet open (gate L-core): calm "Šī māja vēl nav atvērta pieteikšanai — mēģiniet vēlāk".
4. **Access method:** "Ar e-pastu" (explains why, no name needed) or "Bez e-pasta — ar PIN" (6 big digits; "Mans
   PIN" card hint; an easy PIN like 123456 or a birth date gets the calm "Šis PIN ir pārāk viegli uzminams" message). "Atcerēties šo telefonu" on by default. Then "Gaida apstiprinājumu — parasti 1–2 dienas" with
   read-only heating/outage access, and an illustrated "Pievienot ikonu ekrānam" guide (Android 3-dot menu / iOS share).
   Notification permission is asked only after the first successful report.

### 6.2 Home (Sākums)
- Outage banner when active (pulsing dot): "Aktīvs atslēgums · stāvads 5-K · 4 ziņojumi · pirms 12 min".
  Otherwise one calm line: "Šobrīd viss kārtībā · pēdējais atslēgums pirms 9 d".
- 2×2 big actions (96px): **Ziņot par siltumu · Vai ir ūdens? · Pārbaudīt auto · Manas caurlaides** — the two
  parking tiles render only when `modules.parking` is on; otherwise one row of two tiles (heating, water).
- Active survey round card: "Novembra apkures pārbaude · aizpildījuši 21 no 36" + progress + "Aizpildīt (1 min)".
- Mini riser map of my stairwell (read-only → Siltums).
- "Mana māja" card: series 602, manager, how to file a request, emergency phone.
- Deadline card: "Ūdens uzskaites lēmums līdz 01.01.2027" with countdown chip.
- No feed. Senior mode shows only the 4 actions + status line.

### 6.3 Siltums (heating grid)
Header "Siltums" + round chip ("Kārta: 2026-11" / "Pēdējās 7 dienas"); mode switch **Siltums | Ūdens**; stairwell
tabs; grid; sticky collapsible legend; "Stāvadu kopsavilkums" list (worst first: riser code, status chip,
coverage bar "4/9 stāvi" (count only), counts per state "auksts 3 · silts 2"). **No 9-segment per-floor bar for
residents** — staff (admin; elder in own stairwell) may expand a per-floor bar. Primary button "Ziņot" (56px,
labelled, above nav). Admin, and elder for own stairwell: "Lejupielādēt PDF pārvaldniekam".

### 6.4 Report sheet
Title "Dz. 1xx · kā silda radiatori?"; shortcut "Visās telpās vienādi"; room rows from the template ("Istaba 1",
"Virtuve", "Vannas istaba (dvieļu žāvētājs)") each with a 4-option segmented control; optional "Pievienot
temperatūru" (stepper) and "Piezīme pārvaldniekam/vecākajam" (max 280, private); sticky "Saglabāt". Confirmation
screen stays until "Labi": "Paldies! Jau ziņojuši 3 dzīvokļi Jūsu stāvadā. Vairāk neko darīt nav vajadzīgs."
Undo available 8 s. Riser fill animation (220 ms, bottom→top) unless reduced motion.

### 6.5 "Vai ir ūdens?" (outage)
Three big toggles Nav aukstā ūdens / Nav karstā ūdens / Nav siltuma → "Kopš kura laika?" chips (tikko / ~1 h /
šorīt) → "Jau ziņojuši 6 dzīvokļi Jūsu stāvadā. Tas nav tikai pie Jums." Manager reply shown in one plain
sentence: "RNP: remonts līdz 18:00". Emergency phone + "nav avārijas kanāls" always visible.

### 6.6 Stāvvieta — lookup
Legal banner (2 sentences, current Riga rule). Huge plate input + reason chips + "Pārbaudīt". Result (3xl):
success icon "Reģistrēts šajā mājā" or neutral info icon "Nav atrasts reģistrā" + "Tas var būt viesis vai vēl
nereģistrēts kaimiņš · Sarakstā pašlaik 64% dzīvokļu". Under it: "Dzīvoklis netiek rādīts. Šī pārbaude tiek
reģistrēta." Actions: "Ziņot par svešu auto", "Pārbaudīt citu". Rate-limit state with friendly countdown.

### 6.7 Manas caurlaides
Header "Pamata 1 · Viesu 1". Permit card: type chip (Pamata = solid primary, Viesu = outlined), number (tnum,
large), plate badge(s), "līdz 31.03.2027" + "Beidzas drīz" chip ≤30 d, status. Actions: "Mainīt auto" (new plate →
"nodot veco caurlaidi" → gaida apstiprinājumu), "Pievienot auto" (always ends in "Gaida apstiprinājumu"),
"Dzēst manu numuru" (2-step; if a main permit is bound to the car, step 2 says "Jūsu pamata caurlaide Nr. … kļūs
nederīga, līdz piesaistīsiet citu auto" and offers "Mainīt auto" as the primary button). Privacy box
before saving: "Numuru redz tikai stāvvietas atbildīgais un administrators. Pārējie redz tikai «ir sarakstā» vai
«nav». Numurs glabājas šifrēts." Alternative: "Nevēlos ievadīt pats — to izdarīs vecākais".

### 6.8 Sighting sheet
Lot select, time prefilled, plate input, optional in-app photo via a "Fotografēt" button (converted to JPEG on the
phone; "Nefotografējiet cilvēkus · Foto tiks dzēsts pēc 30 dienām"), submit. No free text visible to others.
Confirmation is always the same neutral "Paldies, ziņojums nodots stāvvietas atbildīgajam" — never "reģistrēts" /
"nav reģistrēts" (a sighting must not work as a lookup). Daily limit reached → calm "Šodien ziņojumu limits
sasniegts — paldies, ziņojumi jau nodoti".

### 6.9 Elder panel ("Palīdzība kaimiņiem", resident app, not Filament)
Approval queue (code redeemed, apartment, role → Apstiprināt / Noraidīt), assisted onboarding ("Tas esmu es" →
set PIN on the elder's phone), PIN reset (shows a one-time temporary code in large mono digits once, "Iedodiet
šo kodu kaimiņam personīgi — derīgs 24 h"; the neighbour then sets a new PIN; "Šī darbība tiek reģistrēta"),
proxy report for own stairwell (marked "pēc telefona"), outage notice composer, WhatsApp
digest share card, campaign progress.

### 6.10 Admin (Filament 5, same tokens + Inter)
Building generator wizard (series → stairwells → floors → first apt no. → numbering direction → layout preset →
preview grid identical to the resident grid → generate), membership queue, invite batch PDF, risers with
confidence, survey rounds, incidents, manager reports, permits & vehicles (masked, reveal modal with reason +
"Šī darbība tiek reģistrēta"), parking analytics tiles, exports (request/approve), audit log, series catalogue.

## 7. Empty states, errors, offline
- Heating with no data: "Šajā stāvadā vēl neviens nav ziņojis. Esiet pirmais — tas aizņem 30 sekundes."
- Parking with no permits: "Jums vēl nav reģistrētu caurlaižu" + "Pieteikt caurlaidi" + one-line rule explanation.
- Offline: grey top banner "Nav savienojuma — mēģiniet vēlreiz, kad būsiet tiešsaistē" / "Нет соединения —
  попробуйте ещё раз, когда будете онлайн". **No queued saves in the MVP** (Livewire requests cannot be replayed by
  a service worker): open sheets keep the user's selection in memory, "Saglabāt" is disabled with the banner
  reason, and re-enables on reconnect. No "will be sent later" promise anywhere.

## 8. Print templates (same design language, black-and-white safe)
- **Invite letter A5:** LV left / RU right (or RU first for RU-speaking stairwells), apartment number huge, code
  `XXXXX-XXXXX` in mono, short URL, QR (encodes `/join#c=…` — fragment, never a query string), 3 steps, elder
  contact placeholder entered by the elder at print time, controller name + privacy-notice short URL line,
  "Neizlieciet šo kodu publiski", detachable "Mans PIN" card. Min 16–18 pt. Printable only once gate L-core is open.
- **Manager report A4:** report number, building, period, method + coverage disclaimer, riser table (incl.
  "viss stāvads auksts" triage count), column-level grid snapshot with icons/hatch (per-floor snapshot only when the
  house enabled `heating_floors`), sharing-flags line ("Stāvi: nav kopīgoti · Dzīvokļu numuri: nav kopīgoti"),
  emergency phone footer.
- **Parking-lot sign (Art. 14):** controller, purpose, retention, QR to the notice, LV/RU.
- **Fridge card A5 (Phase 2):** the 3 actions, URL, elder contact.

## 9. PWA icon & brand
Maskable icon: petrol panel-house silhouette with one highlighted window, no text (works on any domain).
Wordmark "Mājas Palīgs" in Inter 700.

## 10. Do not
Chat/feed/comments; names/avatars; public door QR codes; map/satellite pickers; icon-only buttons; hamburger as
primary nav; placeholder-as-label; text <13px; glassmorphism; low-contrast greys; red/green as the only pair;
Google Fonts/Maps; analytics SDKs (self-hosted cookieless Plausible only if needed); any real chat content, phone
numbers or plates in designs, seeds or screenshots (use synthetic "AB1234 — piemērs").

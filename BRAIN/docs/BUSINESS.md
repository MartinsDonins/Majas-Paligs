# BUSINESS — Mājas Palīgs

> Synthesis of the economist, building-manager and "stingy neighbour" panel input (2026-10-17).
> Figures marked *(model)* are assumptions, not sourced data. Validate with 3–5 elders/associations before
> any price goes public.

## 1. Who pays, who uses
| Actor | Role | Pays? |
|---|---|---|
| Residents (owners, tenants, family) | report, look up, declare | **Never.** Free forever, no ads, no paywalled core |
| Section elder / floor captain | champion user, onboarding, approvals | No (gets tools, maybe free year via referral) |
| Owners' community (kopība) / residents' association (biedrība) | controller of house data, buyer for one building | Yes — building plan from the existing fund |
| Building manager company (RNP-type, small private managers) | receives structured requests, portfolio view | Yes — Phase 2+ manager tier |
| CoreDigify (operator) | **controller** for platform accounts (login identity, credentials, security logs); **processor** (Art. 28 DPA) for house data; platform admin | — |

Price anchors from the chat: residents argue over ~4 €/month water loss and paid paper invoices, and resisted a
~10 € association fee. **Any per-resident charge kills adoption.** A building-level price paid from the common
fund clears a vote more easily.

## 2. Pricing hypothesis
| Plan | Content | Price *(model)* |
|---|---|---|
| **Bezmaksas (Free)** | Resident app, heating grid, outages + "Man arī", plate lookup yes/no, own permits, LV/RU, GDPR self-service | 0 € — always |
| **Pilots** | Everything, Pūces iela 51 | 0 € through 2027-06 |
| **Māja (building)** | Registry tools for officer/admin: police/manager exports, manager report package, parking analytics, decision register, welcome pack, (later) documents and meeting organiser | **0.20 €/apartment/month**, billed per building annually; min 15 €/mo, cap 60 €/mo (180 apts = 36 €/mo ≈ 432 €/yr) |
| **Balsošanas pakete** (entry offer) | BIS registration checklist, proxy/agenda templates, participation tracker for one vote | 29–49 € one-off per meeting |
| **Pārvaldnieks (manager tier, Phase 2–3)** | Manager portal across buildings, acknowledge/status SLA, aggregated "N apartments reported" dashboards, anonymous series benchmarks (opt-in) | 0.10 €/apartment/month for the portfolio *(model)* |

Benchmarks: management fee ~0.33 €/m² + VAT (Līvāni 2021 example, lvportals.lv); a 50 m² flat pays ~17 €/month
for management, so 0.20 € for software is ~1%. No statutory min/max management fee (MK Nr. 408 gives only the
method). Panel range was 0.10–0.50 €/apt/month; 0.20 € is the midpoint that survives the "stingy" test.
No public competitor pricing found.

Billing: **manual invoicing via CoreDigify accounting** until ≥10 paying buildings (Stripe/VAT complexity eats
tiny revenue). Invoices must suit a kopība/biedrība budget line.

## 3. Costs *(model)*
| Item | Monthly |
|---|---|
| Share of existing Coolify server (EU VPS, Postgres + Redis + PHP) | 5–8 € |
| Own .lv domain | ~1 € (≈10 €/yr) |
| Encrypted off-site DB backups (EU S3-compatible) | ~1 € |
| Transactional e-mail (EU provider, free/low tier) | 0–5 € |
| GlitchTip (already self-hosted) | 0 € |
| SMS / WhatsApp Business API / maps / paid analytics | **0 € — not used** |
| **Total** | **< 15 €/month** until paying buildings exist |
Per-building infra cost at 180 apartments: < 0.10 €/apartment/**year**. Gross margin > 90% from a few buildings.
The real cost is **founder time**: 15–30 h per building at start (printed invites, elder training). A repeatable
elder playbook + print kit is required before targeting 50+ buildings.

Cost guardrails in code: lookup/redemption rate limits, sighting/photo quotas (10/user/day, 100 sightings + 50
photos/building/day, 500 MB photo storage per building — MVP-SPEC C.5), per-building export quotas, queue
jobs, no runtime translation APIs, AI (later) only on demand with a monthly per-building token budget and cheap model.

## 4. Market *(order of magnitude — verify with CSP/VZD)*
- Latvia: ~29.6k apartment buildings, ~520k apartments (RTU, 2013).
- Riga: 11,782 buildings with 3+ apartments (40% of residential buildings), 32% of national housing stock
  (Riga housing policy draft 2024–2030).
- Riga large-panel estates: ~11 series, ~200k apartments, ~40% of Riga's stock (RTU/Springer).
- No public count of 602-series buildings → task: compile series-by-building list from VZD/BIS (intern task).
  104 is reported as the most common Riga series → seed it right after 602.
- Ceiling for a solo operator *(model)*: 3–5% of Riga's ~11.8k buildings (350–600 buildings) ≈ 300–500k € ARR;
  100 buildings × 180 apts × 0.20 € × 12 ≈ 43k € ARR.

## 5. Competition & positioning
| Player | What it is | Gap we fill |
|---|---|---|
| BIS (bis.gov.lv) | State system: owner registration, votes, house file | We never replicate votes; we link, remind, prepare |
| Manager portals (e.g. RNP e-pārvaldnieks; Kedra Software client portal "MansDzib.lv" per app listing — verify) | Manager-side bills, meter readings, applications | Works only if the manager runs it; no resident self-organisation, no riser view, no parking registry |
| WhatsApp groups | Where residents actually are | We feed them (digest share), we don't compete |
| Global (BuildingLink, Plentific, Grata) | Not localised, manager-paid | LV/RU, Soviet-series data, price |
Defensible moat: **series catalogue + riser data + (opt-in, k ≥ 5) series benchmarks** — nobody else holds them.
Positioning: neutral "house operating layer"; invite the manager early; manager acknowledge button even if the
pilot manager never uses it — that is what makes the product credible to every other manager.

## 6. Name & domain
- Product name: **Mājas Palīgs** (RU: «Помощник дома»).
- **Now:** `kaimini.coredigify.com` for development and staging (free; path tenancy `/b/{slug}`).
- **Before printing invite letters** (printed URLs are permanent, and a long agency subdomain looks like phishing to
  older residents): register an own `.lv` domain.
  **Owner's shortlist** (the decision set):
  - **kaiminu.lv** — short, friendly, works for LV; fine for RU readers in Latin. Architect's pick within the shortlist.
  - **stavads.lv** — great for the heating/series lead magnet ("602 stāvadu karte"), weaker as a general brand.
  - **kopiba.lv** — legally loaded (kopība = owners' community). **Taken** (Openprovider check 2026-10-17).
  **Architect proposal (not on the owner's list, for the owner to accept or ignore):** **majaspaligs.lv** — name =
  URL, easiest to trust and to type from a letter. Suggested combo: majaspaligs.lv (or kaiminu.lv) as the app host
  + stavads.lv for the series lead magnet.
  Availability (Openprovider 2026-10-17, see MANUAL-TODO): free — kaiminu.lv, kaiminos.lv, kaimi.lv; taken —
  kopiba.lv, kaimini.lv; **stavads.lv and majaspaligs.lv not yet checked**. Owner buys 1–2 (≈10 €/yr each). Old host
  301-redirects forever; invite QR codes point to the own domain from the first print.

## 7. Go-to-market
1. **Pilot proof (Pūces 51, Q4 2026):** wave 1 stairwell 5, then stairwells 1–4. Metrics: % apartments activated,
   weekly active apartments, reports per week, risers confirmed, permits/vehicles declared, duplicate/guest-as-permanent
   cases found (aggregate), time saved by the elder (hand lists), first manager report delivered + response.
   **Stop rule:** < 25% of stairwell-5 apartments active within 60 days → fix outreach before Phase 2.
2. **Timely hook — water metering decision (deadline 2027-01-01, every Latvian building):** free "meter decision
   helper" + SEO/Facebook-group content in LV/RU; collects elders' contacts.
3. **Same-series expansion:** elders and associations of 602 (then 104/467/119) buildings in Purvciems, Pļavnieki,
   Imanta, Ziepniekkalns, Mežciems; free public PDF "602 sērijas stāvadu karte un tipiskās apkures problēmas" as
   lead magnet; public demo grid of a fictional 602 building.
4. **Referral:** elder gets a free year for each additional same-series building that joins.
5. **Managers:** small private managers and associations first (short cycle); RNP/municipal managers are a long,
   procurement-bound cycle — present the first heating report in person and offer the acknowledge portal free.
   Riga's 2025 push for owners to choose a management model → many active owner meetings → vote-readiness pack.
6. **Compliance pack as sales asset (Phase 3):** per-building DPA, Art. 30 record, LIA, DPIA, privacy notice —
   generated when a module is enabled.
7. **Later revenue lines:** manager per-ticket contractor dispatch; anonymised series data for renovation planners
   (ALTUM, energy auditors) — aggregates only.

## 8. What managers pay for (manager persona)
Fewer inbound calls (status page "known issue, riser X, crew dispatched, ETA"); one de-duplicated ticket per
riser with N apartments confirming; outage notices pushed only to affected apartments; clean aggregated defect
and heating data per building/series; owner BIS/e-mail hygiene (yes/no only); meeting/vote preparation;
paper trail for heat-supplier disputes. They will cancel over naming-and-shaming — keep language factual and
moderation tools in place.

## 9. Business risks
- Willingness to pay untested; owner meeting participation is low → building plan needs a vote or an elder budget.
- Legal: unclear controller for parking data; police-list rule changed twice in 2026 (see PLANNING §2.1).
- Incumbent response: managers or portal vendors add parking/report modules → moat = series data + neutrality.
- Founder time: manual onboarding does not scale without the elder playbook.
- Domain move after printing breaks letters → decide domain before the first print.
- Pilot manager change during the contract vote → data must stay with the house.

# Datu apstrādes līgums ar CoreDigify

<!-- req:parties -->
## 1. Puses

**Pārzinis (māja):** {controller.name}. Kontakts: {controller.contact}. Pārstāvības pamats: {controller.basis_ref}.
Māja: {building.name}, {building.address}.

**Apstrādātājs:** {processor.name}. Kontakts: {processor.contact}.

Puses noslēdz šo līgumu saskaņā ar Vispārīgās datu aizsardzības regulas (VDAR) 28. pantu.
<!-- /req -->

<!-- req:subject -->
## 2. Priekšmets un termiņš

Apstrādātājs nodrošina mājas lietotni "Mājas Palīgs" (Kaimiņos) un apstrādā personas datus tikai pārziņa uzdevumā un
tikai šajā līgumā un tā A pielikumā norādītajiem moduļiem. Līgums ir spēkā līdz tā izbeigšanai un vēl 30 dienas pēc tam,
kamēr dati tiek atdoti vai dzēsti (7. punkts).
<!-- /req -->

<!-- req:nature -->
## 3. Apstrādes raksturs un mērķis

- **Pamata dati:** dzīvokļu piederības pārbaude, kāpņu telpu vecāko un administratoru lomas, ielūgumu kodi.
- **Siltums un atslēgumi:** iedzīvotāju ziņojumi par apkuri un atslēgumiem, stāvadu kopsavilkumi, pārskati pārvaldniekam
  bez dzīvokļu numuriem.
- **Stāvvieta:** caurlaižu un auto numuru reģistrs (šifrēts), pārbaude "ir / nav reģistrā", ziņojumi par auto.
- **Iedzīvotāju saraksts:** mājas kontaktu reģistrs administrēšanai (vārdi, tālruņi, e-pasti — šifrēti), redzams tikai
  administratoram un savas kāpņu telpas vecākajam.

Apstrādātājs apstrādā tikai to moduļu datus, kas norādīti A pielikumā.
<!-- /req -->

<!-- req:categories -->
## 4. Datu kategorijas un datu subjekti

**Datu subjekti:** iedzīvotāji, dzīvokļu īpašnieki, īrnieki, lomu turētāji (administratori, vecākie, stāvvietas
atbildīgie).

**Datu kategorijas:** konta dati (e-pasts vai ieejas kods), dzīvokļa piederība un loma, ziņojumi par apkuri un
atslēgumiem; stāvvietas modulī — auto numuri un caurlaides; iedzīvotāju sarakstā — vārds, tālrunis, e-pasts, piezīme.
Īpašu kategoriju datus (VDAR 9. pants) neapstrādā.
<!-- /req -->

<!-- req:obligations -->
## 5. Apstrādātāja pienākumi

1. Apstrādāt datus tikai pēc pārziņa dokumentētiem norādījumiem (šis līgums, lietotnes iestatījumi, rakstiski
   norādījumi).
2. Nodrošināt, ka darbinieki un pilnvarotās personas ievēro konfidencialitāti.
3. Īstenot VDAR 32. panta drošības pasākumus (C pielikums).
4. Palīdzēt pārzinim atbildēt uz datu subjektu pieprasījumiem un veikt novērtējumus (DPIA).
5. Dati tiek glabāti tikai Eiropas Savienībā / EEZ.
<!-- /req -->

<!-- req:subprocessors -->
## 6. Apakšapstrādātāji

Pārzinis piekrīt šādiem apakšapstrādātājiem:

{subprocessors.list}

Par jaunu apakšapstrādātāju apstrādātājs paziņo vismaz 14 dienas iepriekš; pārzinis var iebilst.
<!-- /req -->

<!-- req:breach -->
## 7. Datu aizsardzības pārkāpumi un līguma beigas

Par personas datu aizsardzības pārkāpumu apstrādātājs paziņo pārzinim bez nepamatotas kavēšanās, ne vēlāk kā 48 stundu
laikā pēc tam, kad par to uzzinājis. Līgumam beidzoties, apstrādātājs pēc pārziņa izvēles atdod mājas datu eksportu un
30 dienu laikā dzēš datus, izņemot to, kas jāglabā pēc likuma. Pārzinis var pārbaudīt līguma izpildi, iepriekš saprātīgā
termiņā par to paziņojot.
<!-- /req -->

## 8. Kontaktpersonas

<!-- edit:contacts -->
Pārziņa kontaktpersona: (vārds, uzvārds, e-pasts). Apstrādātāja kontaktpersona: CoreDigify atbalsts.
<!-- /edit -->

## 9. Paziņojumu adreses

<!-- edit:notices -->
Paziņojumus sūta uz pārziņa un apstrādātāja kontaktiem, kas norādīti 1. punktā.
<!-- /edit -->

## 10. Īpaši norādījumi

<!-- edit:instructions -->
Īpašu norādījumu nav.
<!-- /edit -->

<!-- req:annexes -->
## A pielikums — moduļi (tvērums)

{dpa.scope_list}

## B pielikums — glabāšanas termiņi

{retention.table}

## C pielikums — tehniskie un organizatoriskie pasākumi

- Šifrēts savienojums (HTTPS, HSTS); datubāze ar TLS un atsevišķām lomām bez dzēšanas tiesībām lietotnei.
- Auto numuri un iedzīvotāju kontakti glabājas šifrēti ar atsevišķām atslēgām; meklēšana tikai ar aklajiem indeksiem.
- Lomas ar mazākajām tiesībām; administratoriem otrs faktors (ieejas atslēga vai autentifikatora kods).
- Nemaināms audita žurnāls ar ķēdes pārbaudi; "Kas skatīja manus datus" iedzīvotājiem.
- Ierobežojumi meklēšanai un atklāšanai; eksports ar četru acu principu.
- Rezerves kopijas šifrētas, ES, 30 dienu rotācija; regulāra atjaunināšana un ievainojamību pārbaude.
<!-- /req -->

## Paraksti

<!-- edit:signatures -->
Pārziņa vārdā: ................................ (vārds, uzvārds, datums)

Apstrādātāja vārdā: ................................ (vārds, uzvārds, datums)
<!-- /edit -->

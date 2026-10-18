@php
    /*
     * Invite letter, A4 portrait (folds to DL for the mailbox), one page per language per apartment (duplex: LV
     * front / RU back, or RU first). A5 could not hold the content at the senior-friendly sizes — even the old 12 pt
     * A5 version spilled onto a second sheet per language.
     * DejaVu Sans covers Latvian diacritics and Cyrillic. Black-and-white safe. No personal data.
     * Type sizes (DESIGN-SYSTEM 8, review fix): body / steps / URL 16 pt, warning 14 pt, labels 12 pt; only the
     * controller / privacy legal line stays small (9.5 pt). Code + QR side by side, URL and steps full width so
     * nothing wraps mid-word; each language fits ONE page (InviteLetterTest checks the page count).
     */
    $t = fn (string $key, string $locale, array $r = []) => __('letter.'.$key, $r, $locale);
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 12mm 15mm 10mm 15mm; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { color: #111; font-size: 16pt; line-height: 1.3; }
    .page { page-break-after: always; position: relative; }
    .page:last-child { page-break-after: auto; }
    .top { width: 100%; border-bottom: 2px solid #0B4F55; padding-bottom: 2mm; }
    .brand { font-size: 12pt; font-weight: bold; color: #0B4F55; }
    .house { font-size: 12pt; }
    .apt { font-size: 40pt; font-weight: bold; line-height: 1; margin: 3mm 0 1mm; }
    .where { font-size: 16pt; }
    .lead { font-size: 18pt; font-weight: bold; margin: 2mm 0 2mm; }
    table.grid { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .code { font-family: "DejaVu Sans Mono", monospace; font-size: 26pt; font-weight: bold; letter-spacing: 2pt; border: 2px solid #111; padding: 2.5mm 4mm; display: inline-block; }
    .url { font-size: 18pt; font-weight: bold; }
    ol { margin: 2mm 0 0 7mm; padding: 0; }
    ol li { font-size: 17pt; margin-bottom: 1mm; }
    .warn { font-size: 15pt; font-weight: bold; border: 1.5px solid #111; padding: 2mm 3mm; margin-top: 3mm; }
    .small { font-size: 12pt; color: #222; }
    .legal { font-size: 9.5pt; color: #333; margin: 3mm 0 0; }
    .cut { border-top: 1px dashed #555; margin-top: 4mm; padding-top: 2mm; }
    .card { border: 1.5px solid #111; padding: 2mm 4mm; width: 100%; }
    .line { border-bottom: 1px solid #111; height: 8mm; }
    .label { font-size: 12pt; }
</style>
</head>
<body>
@foreach ($letters as $letter)
    @foreach ($order as $lang)
        <div class="page">
            <table class="top"><tr>
                <td class="brand">{{ $t('brand', $lang) }}</td>
                <td class="house" style="text-align:right">{{ $building->name }}, {{ $building->city }}</td>
            </tr></table>

            <div class="apt">{{ $t('apartment', $lang, ['n' => $letter['number']]) }}</div>
            <div class="where">{{ $t('section', $lang, ['n' => $letter['section']]) }} · {{ $t('floor', $lang, ['n' => $letter['floor']]) }}</div>
            <div class="lead">{{ $t('invite', $lang) }}</div>

            <table class="grid"><tr>
                <td style="width:62%">
                    <div class="small">{{ $t('your_code', $lang) }}</div>
                    <div class="code">{{ $letter['code'] }}</div>
                </td>
                <td style="width:38%; text-align:right">
                    <img src="{{ $letter['qr'] }}" style="width:36mm;height:36mm" alt="">
                    <div class="small" style="text-align:right">{{ $t('scan', $lang) }}</div>
                </td>
            </tr></table>

            <div class="small" style="margin-top:2mm">{{ $t('open', $lang) }}</div>
            <div class="url">{{ $joinUrl }}</div>
            <ol>
                <li>{{ $t('step1', $lang) }}</li>
                <li>{{ $t('step2', $lang) }}</li>
                <li>{{ $t('step3', $lang) }}</li>
            </ol>

            <div class="warn">{{ $t('do_not_post', $lang) }} · {{ $t('valid_until', $lang, ['date' => $validUntil]) }}</div>
            <p class="small" style="margin:3mm 0 0">{{ $t('not_bis', $lang) }}</p>
            @if ($elderContact)
                <p class="small" style="margin:2mm 0 0">{{ $t('elder', $lang) }}: {{ $elderContact }}</p>
            @else
                <p class="small" style="margin:2mm 0 0">{{ $t('elder', $lang) }}: ______________________</p>
            @endif
            <p class="legal">{{ $t('controller', $lang, ['controller' => $controller ?? '—']) }} {{ $t('privacy', $lang, ['url' => $privacyUrl]) }}</p>

            @if ($loop->first)
                <div class="cut">
                    <div class="small">✂ {{ $t('cut', $lang) }}</div>
                    <table class="card"><tr>
                        <td colspan="2" style="font-weight:bold">{{ $t('my_pin', $lang) }} <span class="small" style="font-weight:normal">· {{ $t('pin_secret', $lang) }}</span></td>
                    </tr><tr>
                        <td style="width:62%; padding-right:4mm"><div class="label">{{ $t('handle', $lang) }}</div><div class="line"></div></td>
                        <td style="width:38%"><div class="label">PIN</div><div class="line"></div></td>
                    </tr></table>
                </div>
            @endif
        </div>
    @endforeach
@endforeach
</body>
</html>

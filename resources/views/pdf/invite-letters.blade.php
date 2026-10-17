@php
    /*
     * Invite letter, A5 portrait, one page per language per apartment (duplex: LV front / RU back, or RU first).
     * DejaVu Sans covers Latvian diacritics and Cyrillic. Black-and-white safe. No personal data.
     */
    $t = fn (string $key, string $locale, array $r = []) => __('letter.'.$key, $r, $locale);
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 11mm 11mm 9mm 11mm; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { color: #111; font-size: 12pt; line-height: 1.32; }
    .page { page-break-after: always; position: relative; }
    .page:last-child { page-break-after: auto; }
    .top { width: 100%; border-bottom: 2px solid #0B4F55; padding-bottom: 2mm; }
    .brand { font-size: 11pt; font-weight: bold; color: #0B4F55; }
    .house { font-size: 11pt; }
    .apt { font-size: 40pt; font-weight: bold; line-height: 1; margin: 3mm 0 1mm; }
    .apt small { font-size: 12pt; font-weight: normal; }
    .lead { font-size: 15pt; font-weight: bold; margin: 2mm 0; }
    table.grid { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .code { font-family: "DejaVu Sans Mono", monospace; font-size: 22pt; font-weight: bold; letter-spacing: 1.5pt; border: 2px solid #111; padding: 2mm 3mm; display: inline-block; }
    .url { font-size: 13pt; font-weight: bold; }
    ol { margin: 2mm 0 0 5mm; padding: 0; }
    ol li { font-size: 13.5pt; margin-bottom: 1.5mm; }
    .warn { font-size: 11pt; font-weight: bold; border: 1px solid #111; padding: 1.5mm 2mm; margin-top: 2mm; }
    .small { font-size: 9.5pt; color: #333; }
    .cut { border-top: 1px dashed #555; margin-top: 3mm; padding-top: 2mm; }
    .card { border: 1.5px solid #111; padding: 2mm 3mm; width: 74mm; }
    .line { border-bottom: 1px solid #111; height: 7mm; }
    .label { font-size: 9.5pt; }
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

            <div class="apt">{{ $t('apartment', $lang, ['n' => $letter['number']]) }}
                <small>· {{ $t('section', $lang, ['n' => $letter['section']]) }} · {{ $t('floor', $lang, ['n' => $letter['floor']]) }}</small>
            </div>
            <div class="lead">{{ $t('invite', $lang) }}</div>

            <table class="grid"><tr>
                <td style="width:62%">
                    <div class="small">{{ $t('your_code', $lang) }}</div>
                    <div class="code">{{ $letter['code'] }}</div>
                    <div style="margin-top:2mm" class="small">{{ $t('open', $lang) }}</div>
                    <div class="url">{{ $joinUrl }}</div>
                    <ol>
                        <li>{{ $t('step1', $lang) }}</li>
                        <li>{{ $t('step2', $lang) }}</li>
                        <li>{{ $t('step3', $lang) }}</li>
                    </ol>
                </td>
                <td style="width:38%; text-align:right">
                    <img src="{{ $letter['qr'] }}" style="width:40mm;height:40mm" alt="">
                    <div class="small" style="text-align:right">{{ $t('scan', $lang) }}</div>
                </td>
            </tr></table>

            <div class="warn">{{ $t('do_not_post', $lang) }} · {{ $t('valid_until', $lang, ['date' => $validUntil]) }}</div>
            <p class="small" style="margin-top:2mm">{{ $t('not_bis', $lang) }}</p>
            <p class="small">{{ $t('controller', $lang, ['controller' => $controller ?? '—']) }} {{ $t('privacy', $lang, ['url' => $privacyUrl]) }}</p>
            @if ($elderContact)
                <p class="small">{{ $t('elder', $lang) }}: {{ $elderContact }}</p>
            @else
                <p class="small">{{ $t('elder', $lang) }}: ________________________________</p>
            @endif

            @if ($loop->first)
                <div class="cut">
                    <div class="small">✂ {{ $t('cut', $lang) }}</div>
                    <div class="card">
                        <div style="font-weight:bold">{{ $t('my_pin', $lang) }}</div>
                        <div class="label">{{ $t('handle', $lang) }}</div><div class="line"></div>
                        <div class="label">PIN</div><div class="line"></div>
                        <div class="small">{{ $t('pin_secret', $lang) }}</div>
                    </div>
                </div>
            @endif
        </div>
    @endforeach
@endforeach
</body>
</html>

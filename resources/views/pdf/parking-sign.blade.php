@php
    /*
     * Art. 14 GDPR parking-lot sign, A4 portrait, LV top / RU bottom (PARK-007, DESIGN-SYSTEM §8). Controller,
     * purpose, retention, where to read the full notice (QR + short URL). Black-and-white safe, ≥ 14 pt body.
     */
    $lv = fn (string $k, array $r = []) => __('parking.sign.'.$k, $r, 'lv');
    $ru = fn (string $k, array $r = []) => __('parking.sign.'.$k, $r, 'ru');
    $controllerName = $controller ?? __('parking.sign.controller_missing', [], 'lv');
@endphp
<!DOCTYPE html>
<html lang="lv">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 14mm; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { color: #111; font-size: 13pt; line-height: 1.35; }
    .head { border: 3px solid #111; padding: 5mm; margin-bottom: 4mm; }
    h1 { font-size: 24pt; margin: 0 0 2mm; }
    h2 { font-size: 15pt; margin: 0 0 1.5mm; }
    .lot { font-size: 15pt; font-weight: bold; }
    .block { margin-bottom: 5mm; }
    .k { font-weight: bold; }
    .qr { text-align: center; margin: 3mm 0; }
    .qr img { width: 42mm; height: 42mm; }
    .url { text-align: center; font-size: 11pt; }
    hr { border: 0; border-top: 1.5px dashed #555; margin: 5mm 0; }
</style>
</head>
<body>
    <div class="head">
        <h1>{{ $lv('title') }} · {{ $ru('title') }}</h1>
        <div>{{ $building->address_line }}, {{ $building->city }}</div>
        @if ($lot !== '')
            <div class="lot">{{ $lot }}</div>
        @endif
    </div>

    <div class="block">
        <h2>Latviski</h2>
        <p><span class="k">{{ $lv('controller') }}:</span> {{ $controllerName }}</p>
        <p><span class="k">{{ $lv('purpose') }}:</span> {{ $lv('purpose_text') }}</p>
        <p><span class="k">{{ $lv('data') }}:</span> {{ $lv('data_text') }}</p>
        <p><span class="k">{{ $lv('retention') }}:</span> {{ $lv('retention_text') }}</p>
        <p>{{ $lv('no_people') }}</p>
    </div>
    <hr>
    <div class="block">
        <h2>По-русски</h2>
        <p><span class="k">{{ $ru('controller') }}:</span> {{ $controller ?? __('parking.sign.controller_missing', [], 'ru') }}</p>
        <p><span class="k">{{ $ru('purpose') }}:</span> {{ $ru('purpose_text') }}</p>
        <p><span class="k">{{ $ru('data') }}:</span> {{ $ru('data_text') }}</p>
        <p><span class="k">{{ $ru('retention') }}:</span> {{ $ru('retention_text') }}</p>
        <p>{{ $ru('no_people') }}</p>
    </div>

    <div class="qr"><img src="data:image/png;base64,{{ $qr }}" alt=""></div>
    <div class="url">{{ $lv('more') }} · {{ $ru('more') }}<br>{{ $url }}</div>
</body>
</html>

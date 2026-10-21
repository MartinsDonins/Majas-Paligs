{{-- House document PDF (ROUND2-SPEC §3.4): A4, one language per file, DejaVu Sans (Latvian diacritics + Cyrillic).
     $html is the sanitised CommonMark output of DocumentRenderer (html_input: strip), so it is printed raw. --}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 18mm 18mm 16mm 18mm; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { color: #111; font-size: 10.5pt; line-height: 1.45; }
    .head { border-bottom: 2px solid #0B4F55; padding-bottom: 2mm; margin-bottom: 5mm; }
    .brand { font-size: 9pt; font-weight: bold; color: #0B4F55; }
    .meta { font-size: 9pt; color: #333; }
    h1 { font-size: 16pt; margin: 4mm 0 3mm; }
    h2 { font-size: 13pt; margin: 5mm 0 2mm; }
    h3 { font-size: 11pt; margin: 4mm 0 2mm; }
    ul, ol { margin: 1mm 0 2mm 5mm; padding-left: 4mm; }
    li { margin-bottom: 1mm; }
    a { color: #0B4F55; }
    .disclaimer { margin-top: 8mm; border-top: 1px solid #999; padding-top: 2mm; font-size: 8.5pt; color: #333; }
</style>
</head>
<body>
    <div class="head">
        <div class="brand">Mājas Palīgs · {{ $building }}</div>
        <div class="meta">{{ $title }} · {{ $meta }}</div>
    </div>
    {!! $html !!}
    <p class="disclaimer">{{ $disclaimer }}</p>
</body>
</html>

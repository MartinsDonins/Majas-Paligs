@php
    /*
     * Manager report A4 (DESIGN-SYSTEM §8, MVP-SPEC B.6). Rendered ONLY from the stored snapshot. Black-and-white
     * safe: every state is a word + a letter code + a grey level/pattern, never colour alone. No reporter identity,
     * no private notes. Floors / apartment numbers appear only if the snapshot carries them (house sharing flags).
     */
    $t = fn (string $key, array $rep = []) => __('report.'.$key, $rep, $locale);
    $st = fn (?string $s) => $s ? __('enum.aggregate_status.'.$s, [], $locale) : __('report.no_data', [], $locale);
    $code = ['cold' => 'A', 'lukewarm' => 'R', 'warm' => 'S', 'overheated' => 'P', 'mixed' => '!', 'none' => '–'];
    $shade = ['cold' => '#3a3a3a', 'lukewarm' => '#bdbdbd', 'warm' => '#ececec', 'overheated' => '#6b6b6b', 'mixed' => '#ffffff', 'none' => '#ffffff'];
    $ink = ['cold' => '#ffffff', 'overheated' => '#ffffff'];
    $tz = 'Europe/Riga';
    $fmt = fn (?string $iso) => $iso ? \Carbon\CarbonImmutable::parse($iso)->setTimezone($tz)->format('d.m.Y H:i') : '—';
    $heating = ($r['kind'] ?? 'heating') === 'heating';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 14mm 12mm 16mm 12mm; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { color: #111; font-size: 9.5pt; line-height: 1.35; }
    h1 { font-size: 16pt; margin: 0 0 1mm; }
    h2 { font-size: 11.5pt; margin: 5mm 0 2mm; border-bottom: 1.5px solid #0B4F55; padding-bottom: 1mm; }
    .top { width: 100%; border-bottom: 2.5px solid #0B4F55; padding-bottom: 2mm; margin-bottom: 3mm; }
    .brand { font-weight: bold; color: #0B4F55; font-size: 10pt; }
    .number { font-size: 13pt; font-weight: bold; text-align: right; }
    .meta td { padding: 0.6mm 3mm 0.6mm 0; vertical-align: top; }
    .meta td.k { color: #444; width: 38mm; }
    .note { border: 1px solid #555; padding: 2mm 3mm; margin: 3mm 0; font-size: 9pt; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th, table.data td { border: 0.6px solid #777; padding: 1.2mm 1.5mm; text-align: left; vertical-align: top; }
    table.data th { background: #eee; font-size: 8.5pt; }
    td.num { text-align: right; }
    .grid { border-collapse: separate; border-spacing: 1.2mm; }
    .grid td { width: 15mm; height: 8mm; border: 0.8px solid #333; text-align: center; font-size: 8.5pt; font-weight: bold; }
    .grid td.dash { border-style: dashed; }
    .grid th { font-size: 8pt; color: #444; font-weight: normal; }
    .legend span { display: inline-block; margin-right: 4mm; }
    .sw { display: inline-block; width: 4mm; height: 3mm; border: 0.6px solid #333; vertical-align: middle; }
    .section { page-break-inside: avoid; }
    .footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 8pt; color: #333; border-top: 0.6px solid #777; padding-top: 1.5mm; }
</style>
</head>
<body>
<div class="footer">
    {{ $t('footer.not_emergency') }}
    @if (! empty($r['building']['emergency_phone'])) · {{ $t('footer.emergency') }}: {{ $r['building']['emergency_phone'] }} @endif
    · {{ $r['number'] }} · {{ $t('footer.generated', ['date' => $fmt($r['generated_at'] ?? null)]) }}
</div>

<table class="top"><tr>
    <td class="brand">{{ __('common.app_name', [], $locale) }} · {{ $t('title.'.($heating ? 'heating' : 'outage')) }}</td>
    <td class="number">{{ $r['number'] }}</td>
</tr></table>

<h1>{{ $r['building']['name'] }}</h1>
<table class="meta">
    <tr><td class="k">{{ $t('meta.address') }}</td><td>{{ $r['building']['address'] }}</td></tr>
    @if (! empty($r['building']['manager_name']))
        <tr><td class="k">{{ $t('meta.manager') }}</td><td>{{ $r['building']['manager_name'] }}</td></tr>
    @endif
    <tr><td class="k">{{ $t('meta.period') }}</td><td>{{ $fmt($r['period']['from'] ?? null) }} — {{ $fmt($r['period']['to'] ?? null) }}
        @if (! empty($r['period']['round'])) ({{ $r['period']['round'][$locale] ?? reset($r['period']['round']) }}) @endif</td></tr>
    <tr><td class="k">{{ $t('meta.sections') }}</td><td>{{ implode(', ', array_map(fn ($n) => $n.'.', $r['sections'] ?? [])) }}</td></tr>
    <tr><td class="k">{{ $t('meta.sharing') }}</td><td>
        {{ $t('sharing.floors') }}: {{ ($r['sharing']['heating_floors'] ?? false) ? $t('sharing.shared') : $t('sharing.not_shared') }}
        · {{ $t('sharing.numbers') }}: {{ ($r['sharing']['apartment_numbers'] ?? false) ? $t('sharing.shared') : $t('sharing.not_shared') }}
    </td></tr>
</table>

@if ($heating)
    <div class="note">
        <strong>{{ $t('method.title') }}</strong> {{ $t('method.body') }}
        @if (isset($r['coverage']))
            {{ $t('method.coverage', ['reporting' => $r['coverage']['apartments_reporting'], 'total' => $r['coverage']['apartments_total']]) }}
        @endif
    </div>

    <h2>{{ $t('risers.title') }}</h2>
    @if (empty($r['risers']))
        <p>{{ $t('risers.empty') }}</p>
    @else
        <table class="data">
            <thead><tr>
                <th>{{ $t('csv.riser') }}</th><th>{{ $t('csv.status') }}</th><th>{{ $t('csv.confirmed') }}</th>
                <th>{{ $t('csv.apartments') }}</th><th>{{ $t('csv.floors_covered') }}</th><th>{{ $t('csv.coverage') }}</th>
                <th>{{ $t('risers.counts') }}</th><th>{{ $t('csv.whole_riser') }}</th><th>{{ $t('csv.confidence') }}</th>
                @if ($r['sharing']['heating_floors'] ?? false)<th>{{ $t('csv.floors_reported') }}</th>@endif
                @if ($r['sharing']['apartment_numbers'] ?? false)<th>{{ $t('csv.apartment_numbers') }}</th>@endif
            </tr></thead>
            <tbody>
            @foreach ($r['risers'] as $row)
                <tr>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $code[$row['status']] ?? '' }} · {{ $st($row['status']) }}@if(! empty($row['checked_state'])) · {{ $t('checked') }}@endif</td>
                    <td>{{ $row['confirmed'] ? $t('yes') : $t('no') }}</td>
                    <td class="num">{{ $row['distinct_apartments'] }}</td>
                    <td class="num">{{ $row['floors_covered'] }}</td>
                    <td class="num">{{ $row['distinct_apartments'] }}/{{ $row['coverage_total'] }}</td>
                    <td>{{ $t('risers.counts_value', ['cold' => $row['status_counts']['cold'] ?? 0, 'lukewarm' => $row['status_counts']['lukewarm'] ?? 0, 'warm' => $row['status_counts']['warm'] ?? 0, 'overheated' => $row['status_counts']['overheated'] ?? 0]) }}</td>
                    <td>{{ $row['whole_riser_under'] ? $t('yes') : '—' }}</td>
                    <td>{{ __('enum.riser_confidence.'.$row['confidence'], [], $locale) }}</td>
                    @if ($r['sharing']['heating_floors'] ?? false)<td>{{ implode(', ', $row['floors_reported'] ?? []) }}</td>@endif
                    @if ($r['sharing']['apartment_numbers'] ?? false)<td>{{ implode(', ', $row['apartment_numbers'] ?? []) }}</td>@endif
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
    @if (($r['excluded_disputed'] ?? 0) > 0)
        <p>{{ $t('excluded_disputed') }}: {{ $r['excluded_disputed'] }}</p>
    @endif

    <h2>{{ $t('triage.title') }}</h2>
    <table class="data" style="width:60%">
        @foreach (($r['triage'] ?? []) as $key => $count)
            <tr><td>{{ $t('triage.'.$key) }}</td><td class="num">{{ $count }}</td></tr>
        @endforeach
    </table>

    <div class="section">
        <h2>{{ $t('columns.title') }}</h2>
        <p class="legend">
            @foreach (['cold', 'lukewarm', 'warm', 'overheated', 'mixed'] as $s)
                <span><span class="sw" style="background: {{ $shade[$s] }}"></span> {{ $code[$s] }} = {{ $st($s) }}</span>
            @endforeach
            <span>{{ $t('columns.unconfirmed') }}</span>
        </p>
        @foreach (collect($r['columns'] ?? [])->groupBy('section') as $section => $cols)
            <table class="grid"><tr>
                <th>{{ $t('columns.section', ['n' => $section]) }}</th>
                @foreach ($cols as $c)
                    @php $s = $c['status'] ?? 'none'; @endphp
                    <td class="{{ $c['confirmed'] ? '' : 'dash' }}" style="background: {{ $c['confirmed'] ? $shade[$s] : '#ffffff' }}; color: {{ $c['confirmed'] ? ($ink[$s] ?? '#111') : '#111' }}">
                        {{ $code[$s] ?? '–' }}{{ $c['confirmed'] || (int) ($c['apartments'] ?? 0) === 0 ? '' : '?' }}<br><span style="font-weight:normal;font-size:7pt">{{ $t('columns.position', ['n' => $c['position']]) }}</span>
                    </td>
                @endforeach
            </tr></table>
        @endforeach
    </div>

    @if (! empty($r['floor_grid']))
        <div class="section">
            <h2>{{ $t('floors.title') }}</h2>
            @foreach ($r['floor_grid'] as $sec)
                <table class="grid">
                    <tr><th colspan="5">{{ $t('columns.section', ['n' => $sec['section']]) }}</th></tr>
                    @foreach ($sec['floors'] as $floor)
                        <tr>
                            <th>{{ $floor['floor'] }}.</th>
                            @foreach ($floor['cells'] as $cell)
                                @php $s = $cell['status'] ?? 'none'; @endphp
                                <td style="background: {{ $shade[$s] }}; color: {{ $ink[$s] ?? '#111' }}">
                                    {{ $code[$s] }}@if(isset($cell['apartment']))<br><span style="font-weight:normal;font-size:7pt">{{ $cell['apartment'] }}</span>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            @endforeach
        </div>
    @endif

    @if (! empty($r['timeline']))
        <h2>{{ $t('timeline.title') }}</h2>
        <table class="data" style="width:60%">
            <tr><th>{{ $t('timeline.date') }}</th><th>{{ $t('timeline.reports') }}</th><th>{{ $t('timeline.apartments') }}</th></tr>
            @foreach ($r['timeline'] as $day)
                <tr><td>{{ \Carbon\CarbonImmutable::parse($day['date'])->format('d.m.Y') }}</td><td class="num">{{ $day['reports'] }}</td><td class="num">{{ $day['apartments'] }}</td></tr>
            @endforeach
        </table>
    @endif
@else
    <h2>{{ $t('incidents.title') }}</h2>
    @if (empty($r['incidents']))
        <p>{{ $t('incidents.empty') }}</p>
    @else
        <table class="data">
            <thead><tr>
                <th>{{ $t('csv.kind') }}</th><th>{{ $t('csv.scope') }}</th><th>{{ $t('csv.status') }}</th><th>{{ $t('csv.started') }}</th>
                <th>{{ $t('csv.expected_end') }}</th><th>{{ $t('csv.resolved') }}</th><th>{{ $t('csv.confirmations') }}</th><th>{{ $t('csv.manager_ref') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($r['incidents'] as $i)
                <tr>
                    <td>{{ __('enum.incident_kind.'.$i['kind'], [], $locale) }}@if($i['origin'] === 'notice') · {{ $t('incidents.notice') }}@endif</td>
                    <td>{{ app(\App\Services\Heating\ManagerReportRenderer::class)->scopeText($i['scope'], $locale) }}@if(! empty($i['floors'])) · {{ $t('incidents.floors', ['from' => $i['floors'][0], 'to' => $i['floors'][1]]) }}@endif</td>
                    <td>{{ __('enum.incident_status.'.$i['status'], [], $locale) }}</td>
                    <td>{{ $fmt($i['started_at']) }}</td>
                    <td>{{ $fmt($i['expected_end_at']) }}</td>
                    <td>{{ $fmt($i['resolved_at']) }}</td>
                    <td class="num">{{ $i['confirmations'] }}</td>
                    <td>{{ $i['manager_ref'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endif

<p style="margin-top:6mm" class="note">{{ $t('disclaimer') }}</p>
</body>
</html>

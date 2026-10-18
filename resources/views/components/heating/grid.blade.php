@props(['structure', 'heating', 'mode' => 'heating', 'mine' => null, 'overlay' => null, 'myRiserIds' => [], 'canDispute' => false])
{{--
    Heating / outage map of ONE stairwell (DESIGN-SYSTEM §5.1): floors top-down, 32px floor gutter, cells ≥56px.
    RESIDENT mode: every cell of a column shows the column aggregate (riser + floor = apartment, so no per-floor or
    per-apartment state is ever rendered). STAFF mode (HeatingSectionView::$staff): per-apartment cells.
    OUTAGE mode: hatched overlay on cells inside an open incident's scope.
    Tapping a column opens its riser summary sheet (Alpine panelSwitch; no server round trip).
--}}
@php
    /** @var \App\Services\Heating\Views\HeatingSectionView $heating */
    $cols = (int) $structure['apartments_per_floor'];
    $icons = ['cold' => 'snowflake', 'lukewarm' => 'thermometer-snowflake', 'warm' => 'thermometer-sun', 'overheated' => 'flame', 'mixed' => 'circle-alert', 'none' => 'circle-dashed', 'unconfirmed' => 'circle-help', 'disputed' => 'help-circle'];
    $kindIcons = ['water_cold' => 'droplet-off', 'water_hot' => 'droplet-off', 'heating_none' => 'heater'];
    $first = true;
@endphp
<div x-data="panelSwitch" x-on:keydown="onKeydown" class="w-full">
    <div x-data="gridNav" x-on:keydown="onKeydown">
        <table role="grid" class="w-full border-separate" style="border-spacing: 6px; table-layout: fixed"
               aria-label="{{ __('grid.caption', ['section' => $structure['number'], 'first' => $structure['first'], 'last' => $structure['last']]) }}">
            <colgroup><col style="width: 32px">@for ($p = 1; $p <= $cols; $p++)<col>@endfor</colgroup>
            <thead class="sr-only">
                <tr role="row">
                    <th role="columnheader">{{ __('grid.floor') }}</th>
                    @for ($p = 1; $p <= $cols; $p++)
                        <th role="columnheader">{{ __('grid.column', ['n' => $p]) }}</th>
                    @endfor
                </tr>
            </thead>
            <tbody>
                @foreach ($structure['rows'] as $floor => $row)
                    <tr role="row">
                        <th role="rowheader" scope="row" class="pr-1 text-right text-xs font-semibold text-(--color-fg-muted) tnum">{{ $floor }}.</th>
                        @for ($p = 1; $p <= $cols; $p++)
                            @php
                                $cell = $row[$p] ?? null;
                                $isMine = $cell && $mine && (int) $cell['apartment_id'] === (int) $mine->id;
                                $column = $heating->column($p);
                                $tone = null;
                                $kinds = [];
                                $badge = false;
                                if ($mode === 'outage') {
                                    $kinds = $overlay['cells'][$floor.':'.$p] ?? [];
                                    $class = $kinds !== [] ? 'outage-hit' : '';
                                    $label = $cell ? __('grid.cell_label', ['number' => $cell['number'], 'floor' => $floor]).'. '.($kinds !== [] ? __('outage.cell.affected', ['kinds' => implode(', ', array_map(fn ($k) => __('enum.incident_kind.'.$k), $kinds))]) : __('outage.cell.clear')) : __('grid.empty');
                                    $state = null;
                                } elseif ($heating->staff) {
                                    $apt = $cell ? ($heating->apartments[$cell['apartment_id']] ?? null) : null;
                                    $state = $apt ? $apt['status']->value : 'none';
                                    $class = 'heat-'.$state;
                                    $label = $cell ? __('grid.cell_label', ['number' => $cell['number'], 'floor' => $floor]).'. '.($apt ? __('heating.cell.staff', ['state' => __('heating.state.'.$state), 'rooms' => $apt['rooms']]) : __('heating.state.none')) : __('grid.empty');
                                } else {
                                    $state = $column->displayState();
                                    $tone = $column->tone()?->value;
                                    $class = 'heat-'.$state.($state === 'unconfirmed' && $tone ? ' heat-tone-'.$tone : '');
                                    $badge = $column->checked && ! in_array($state, ['none', 'disputed', 'unconfirmed'], true);
                                    $label = $cell ? __('grid.cell_label', ['number' => $cell['number'], 'floor' => $floor]).'. '.\App\Support\Copy::count('heating.cell.resident', $column->distinctApartments, ['state' => __('heating.state.'.$state), 'count' => $column->distinctApartments]) : __('grid.empty');
                                }
                            @endphp
                            <td role="gridcell" tabindex="{{ $first ? '0' : '-1' }}" class="heat-cell {{ $class }} {{ $isMine ? 'heat-mine' : '' }}"
                                aria-label="{{ $label }}{{ $isMine ? ', '.__('grid.yours') : '' }}" @if ($isMine) aria-current="true" @endif>
                                @if ($cell)
                                    @php
                                        // Never colour alone (DESIGN-SYSTEM 2.3): an unconfirmed column shows its tone's OWN icon
                                        // plus "?", a mixed one "!"; the number + icon of a mixed cell sit on a surface pill.
                                        $iconKey = $state === 'unconfirmed' && $tone ? $tone : $state;
                                        $flag = $state === 'unconfirmed' ? '?' : ($state === 'mixed' ? '!' : null);
                                    @endphp
                                    @if ($isMine)<span class="heat-mine-tag" aria-hidden="true">{{ __('grid.you') }}</span>@endif
                                    <button type="button" tabindex="-1" data-key="col-{{ $p }}" x-on:click="open" aria-hidden="true">
                                        @if ($mode === 'outage')
                                            <span class="tnum">{{ $cell['number'] }}</span>
                                            <span class="flex gap-0.5">
                                                @foreach ($kinds as $k)
                                                    {{ svg('lucide-'.$kindIcons[$k], 'outage-icon-'.$k) }}
                                                @endforeach
                                            </span>
                                        @else
                                            <span class="heat-content">
                                                <span class="tnum">{{ $cell['number'] }}</span>
                                                {{ svg('lucide-'.($icons[$iconKey] ?? 'circle-dashed')) }}
                                            </span>
                                        @endif
                                    </button>
                                    @if ($flag)<span class="heat-flag" aria-hidden="true">{{ $flag }}</span>@endif
                                    @if ($badge)<span class="heat-badge" aria-hidden="true"><x-lucide-check /></span>@endif
                                @endif
                            </td>
                            @php $first = false; @endphp
                        @endfor
                    </tr>
                @endforeach
            </tbody>
            @if ($mode === 'heating')
                <tfoot>
                    <tr>
                        <th class="sr-only">{{ __('heating.coverage.title') }}</th>
                        @for ($p = 1; $p <= $cols; $p++)
                            @php $column = $heating->column($p); @endphp
                            <td class="px-0.5 pt-1">
                                <button type="button" data-key="col-{{ $p }}" x-on:click="open" class="flex min-h-12 w-full items-center rounded-(--radius-sm) px-1 py-1 text-left hover:bg-(--color-surface-2)"
                                        aria-label="{{ __('heating.column.open', ['n' => $p, 'covered' => $column->floorsCovered, 'total' => $column->floorsTotal]) }}">
                                    <x-heating.coverage :covered="$column->floorsCovered" :total="$column->floorsTotal" />
                                </button>
                            </td>
                        @endfor
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    {{-- Riser summary sheets, one per column. --}}
    <div x-show="anyOpen" x-cloak class="sheet-backdrop" x-on:click="close"></div>
    @for ($p = 1; $p <= $cols; $p++)
        @php $risers = $heating->risersAt($p); $column = $heating->column($p); @endphp
        <div x-show="isOpen('col-{{ $p }}')" x-cloak x-trap.noscroll.inert="isOpen('col-{{ $p }}')" data-panel="col-{{ $p }}" role="dialog" aria-modal="true" aria-labelledby="col-h-{{ $p }}" class="sheet">
            <div class="mx-auto max-w-[40rem] space-y-3">
                <div class="sheet-handle" aria-hidden="true"></div>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="col-h-{{ $p }}" class="text-lg">{{ __('heating.column.title', ['n' => $p, 'section' => $structure['number']]) }}</h2>
                        <p class="text-sm muted tnum">{{ __('heating.column.apartments', ['list' => collect($structure['rows'])->map(fn ($r) => $r[$p]['number'] ?? null)->filter()->reverse()->implode(' → ')]) }}</p>
                    </div>
                    <button type="button" x-on:click="close" class="btn">{{ __('common.close') }}</button>
                </div>

                @if ($mode === 'outage')
                    @php $hits = collect($overlay['incidents'] ?? [])->filter(fn ($i) => collect($structure['rows'])->contains(fn ($r, $f) => in_array($i->kind->value, $overlay['cells'][$f.':'.$p] ?? [], true))); @endphp
                    @forelse ($hits as $incident)
                        <a href="{{ route('building.incident', [\App\Tenancy\CurrentBuilding::get(), $incident->id]) }}" class="list-row no-underline text-(--color-fg)">
                            <span class="flex items-center gap-2"><x-dynamic-component :component="'lucide-'.$incident->kind->icon()" class="size-5 outage-icon-{{ $incident->kind->value }}" aria-hidden="true" /> {{ $incident->kind->label() }}</span>
                            <span class="text-sm muted">{{ \App\Support\Copy::count('outage.reports', (int) $incident->confirmations_count, ['count' => (int) $incident->confirmations_count]) }}</span>
                        </a>
                    @empty
                        <p class="muted">{{ __('outage.column.clear') }}</p>
                    @endforelse
                @else
                    @if ($column->disputed)
                        <x-ui.banner variant="warning" icon="help-circle">{{ __('heating.column.disputed') }}</x-ui.banner>
                    @endif
                    @forelse ($risers as $riser)
                        <x-heating.riser-summary :riser="$riser" :staff-view="$heating->staffRisers[$riser->riserId] ?? null"
                                                 :mine="in_array($riser->riserId, $myRiserIds, true)"
                                                 :can-dispute="$canDispute && in_array($riser->riserId, $myRiserIds, true)" />
                    @empty
                        <p class="muted">{{ __('heating.empty.column') }}</p>
                    @endforelse
                    <x-ui.privacy-note>{{ __($heating->staff ? 'heating.privacy.staff' : 'heating.privacy.resident') }}</x-ui.privacy-note>
                @endif
            </div>
        </div>
    @endfor
</div>

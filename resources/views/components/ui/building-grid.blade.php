@props(['section', 'highlight' => null, 'compact' => false, 'caption' => null, 'interactive' => true, 'columns' => null])
{{--
    One stairwell = N columns × floors (top floor on top), 32px floor gutter, cells ≥64×56 (DESIGN-SYSTEM §5.1).
    Structure only (numbers, my apartment, merged marker) unless `columns` (position => ColumnView, Package B) is
    given: then every cell of a column shows that column's aggregate — never a per-floor or per-apartment state.
    The full heating map with summaries is <x-heating.grid>; this component is reused by the generator preview,
    Home and onboarding.
    $section: ['number', 'floors', 'apartments_per_floor', 'first', 'last', 'rows' => [floor => [position => cell]]]
--}}
@php
    $cols = (int) $section['apartments_per_floor'];
    $cellH = $compact ? 'h-7' : 'min-h-14';
    $icons = ['cold' => 'snowflake', 'lukewarm' => 'thermometer-snowflake', 'warm' => 'thermometer-sun', 'overheated' => 'flame', 'mixed' => 'circle-alert', 'none' => 'circle-dashed', 'unconfirmed' => 'circle-help', 'disputed' => 'help-circle'];
@endphp
<div @if($interactive) x-data="gridNav" x-on:keydown="onKeydown" @endif class="w-full">
    <table role="grid" class="w-full border-separate" style="border-spacing: {{ $compact ? '3px' : '6px' }}"
           aria-label="{{ $caption ?? __('grid.caption', ['section' => $section['number'], 'first' => $section['first'], 'last' => $section['last']]) }}">
        <thead class="sr-only">
            <tr role="row">
                <th role="columnheader">{{ __('grid.floor') }}</th>
                @for ($p = 1; $p <= $cols; $p++)
                    <th role="columnheader">{{ __('grid.column', ['n' => $p]) }}</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @php $first = true; @endphp
            @foreach ($section['rows'] as $floor => $row)
                <tr role="row">
                    <th role="rowheader" scope="row" class="w-8 pr-1 text-right text-xs font-semibold text-(--color-fg-muted) tnum">{{ $floor }}.</th>
                    @for ($p = 1; $p <= $cols; $p++)
                        @php
                            $cell = $row[$p] ?? null;
                            $mine = $cell && $highlight !== null && (int) $cell['number'] === (int) $highlight;
                            $merged = $cell && (($cell['merged'] ?? false) || ! ($cell['primary'] ?? true));
                            $col = $columns !== null ? ($columns[$p] ?? null) : null;
                            $state = $columns !== null ? ($col?->displayState() ?? 'none') : null;
                            $tone = $col?->tone()?->value;
                            $heatClass = $state !== null ? 'heat-'.$state.($state === 'unconfirmed' && $tone ? ' heat-tone-'.$tone : '') : '';
                        @endphp
                        <td role="gridcell" tabindex="{{ $first && $interactive ? '0' : '-1' }}"
                            aria-label="{{ $cell ? __('grid.cell_label', ['number' => $cell['number'], 'floor' => $floor]).($state !== null ? '. '.__('heating.state.'.$state) : '').($mine ? ', '.__('grid.yours') : '').($merged ? ', '.__('grid.merged') : '') : __('grid.empty') }}"
                            @if($mine) aria-current="true" @endif
                            class="{{ $cellH }} rounded-[var(--radius-cell)] border text-center align-middle tnum
                                   {{ $state !== null ? 'heat-cell '.$heatClass.($mine ? ' heat-mine' : '') : ($mine ? 'border-(--color-accent) bg-(--color-accent-soft) outline-3 outline-(--color-accent)' : 'border-(--color-line) bg-(--color-surface)') }}
                                   {{ $compact ? 'text-[0.6875rem] font-semibold' : 'text-sm font-semibold' }}"
                            @if ($state !== null && $compact) style="min-height: 28px" @endif>
                            @if ($cell)
                                @php $iconKey = $state === 'unconfirmed' && $tone ? $tone : $state; @endphp
                                @if ($state !== null && $compact)
                                    {{-- Compact (Home mini map): a 12px state icon next to the number, never colour alone. --}}
                                    <span class="inline-flex items-center justify-center gap-0.5 leading-tight">
                                        {{ $cell['number'] }}{{ svg('lucide-'.($icons[$iconKey] ?? 'circle-dashed'), 'size-3 shrink-0', ['aria-hidden' => 'true']) }}@if ($state === 'unconfirmed')<span aria-hidden="true">?</span>@endif
                                    </span>
                                @else
                                    <span class="block leading-tight">{{ $cell['number'] }}</span>
                                @endif
                                @if ($state !== null && ! $compact)
                                    {{ svg('lucide-'.($icons[$iconKey] ?? 'circle-dashed'), 'mx-auto size-4', ['aria-hidden' => 'true']) }}
                                @elseif (! $compact && $mine)
                                    <span class="block text-xs font-bold text-(--color-accent)">{{ __('grid.you') }}</span>
                                @elseif (! $compact && $merged)
                                    <span class="block text-xs text-(--color-fg-muted)">{{ __('grid.merged_short') }}</span>
                                @endif
                            @endif
                        </td>
                        @php $first = false; @endphp
                    @endfor
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

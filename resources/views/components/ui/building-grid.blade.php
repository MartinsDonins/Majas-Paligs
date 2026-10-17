@props(['section', 'highlight' => null, 'compact' => false, 'caption' => null, 'interactive' => true])
{{--
    One stairwell = N columns × floors (top floor on top), 32px floor gutter, cells ≥64×56 (DESIGN-SYSTEM §5.1).
    Package A renders STRUCTURE only (numbers, my apartment, merged marker). Package B adds riser aggregates.
    $section: ['number', 'floors', 'apartments_per_floor', 'first', 'last', 'rows' => [floor => [position => cell]]]
--}}
@php
    $cols = (int) $section['apartments_per_floor'];
    $cellH = $compact ? 'h-7' : 'min-h-14';
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
                        @endphp
                        <td role="gridcell" tabindex="{{ $first ? '0' : '-1' }}"
                            aria-label="{{ $cell ? __('grid.cell_label', ['number' => $cell['number'], 'floor' => $floor]).($mine ? ', '.__('grid.yours') : '').($merged ? ', '.__('grid.merged') : '') : __('grid.empty') }}"
                            @if($mine) aria-current="true" @endif
                            class="{{ $cellH }} rounded-[var(--radius-cell)] border text-center align-middle tnum
                                   {{ $mine ? 'border-(--color-accent) bg-(--color-accent-soft) outline-3 outline-(--color-accent)' : 'border-(--color-line) bg-(--color-surface)' }}
                                   {{ $compact ? 'text-[11px] font-semibold' : 'text-[15px] font-semibold' }}">
                            @if ($cell)
                                <span class="block leading-tight">{{ $cell['number'] }}</span>
                                @if (! $compact && $mine)
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

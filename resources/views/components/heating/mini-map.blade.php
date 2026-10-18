@props(['sections', 'columns', 'active' => null, 'interactive' => true])
{{-- Whole-building mini map: one tile per stairwell, one 12px cell per column (aggregate only). Tap to zoom. --}}
@php $maxPos = max(1, (int) $sections->max('apartments_per_floor')); @endphp
<div class="mini-map" style="--mm-cols: {{ min(6, max(1, $sections->count())) }}; --mm-pos: {{ $maxPos }}" role="group" aria-label="{{ __('heating.minimap.label') }}">
    @foreach ($sections as $s)
        @php $isActive = (int) $active === (int) $s->number; @endphp
        <button type="button" @if ($interactive) wire:click="selectStairwell({{ $s->number }})" @else disabled @endif
                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                aria-label="{{ __('heating.minimap.section', ['n' => $s->number]) }}">
            @for ($p = 1; $p <= $s->apartments_per_floor; $p++)
                @php
                    $col = $columns[$s->id][$p] ?? null;
                    $state = $col?->displayState() ?? 'none';
                    $tone = $col?->tone()?->value;
                @endphp
                <i class="heat-{{ $state }} {{ $state === 'unconfirmed' && $tone ? 'heat-tone-'.$tone : '' }}" aria-hidden="true"></i>
            @endfor
            <span>{{ $s->number }}.</span>
        </button>
    @endforeach
</div>

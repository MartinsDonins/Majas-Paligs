@props(['percent', 'label', 'caption' => null, 'mine' => false])
{{-- Per-stairwell campaign completion — aggregate only (DESIGN-SYSTEM §5). SVG attributes, no inline styles (CSP). --}}
@php
    $p = max(0, min(100, (int) $percent));
    $r = 38;
    $c = round(2 * M_PI * $r, 2);
    $offset = round($c * (1 - $p / 100), 2);
@endphp
<figure class="progress-ring" @if($mine) data-mine @endif role="img" aria-label="{{ $label }}: {{ $p }}%{{ $caption ? ', '.$caption : '' }}">
    <span class="relative inline-flex items-center justify-center">
        <svg viewBox="0 0 88 88" aria-hidden="true">
            <circle class="track" cx="44" cy="44" r="{{ $r }}" fill="none" stroke-width="9" />
            <circle class="bar" cx="44" cy="44" r="{{ $r }}" fill="none" stroke-width="9" stroke-dasharray="{{ $c }}" stroke-dashoffset="{{ $offset }}" />
        </svg>
        <span class="value tnum absolute">{{ $p }}%</span>
    </span>
    <figcaption class="text-center text-sm font-semibold leading-tight">
        {{ $label }}
        @if ($caption)
            <span class="block text-xs font-normal text-(--color-fg-muted) tnum">{{ $caption }}</span>
        @endif
    </figcaption>
</figure>

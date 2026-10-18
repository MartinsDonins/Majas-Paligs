@props(['covered', 'total'])
{{-- "4/9 stāvi" — a plain count bar (never per-floor segments for residents). --}}
@php $pct = $total > 0 ? (int) round(min($covered, $total) / $total * 100) : 0; @endphp
{{-- Wraps (meter above the count) when a column is narrow: 320px with large text. --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-2 gap-y-1']) }}>
    <div class="meter min-w-6 flex-1" role="img" aria-label="{{ __('heating.coverage.label', ['covered' => $covered, 'total' => $total]) }}">
        <span style="width: {{ $pct }}%"></span>
    </div>
    <span class="min-w-0 text-xs font-semibold tnum text-(--color-fg-muted) [overflow-wrap:anywhere]">{{ __('heating.coverage.short', ['covered' => $covered, 'total' => $total]) }}</span>
</div>

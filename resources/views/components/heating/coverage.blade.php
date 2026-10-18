@props(['covered', 'total'])
{{-- "4/9 stāvi" — a plain count bar (never per-floor segments for residents). --}}
@php $pct = $total > 0 ? (int) round(min($covered, $total) / $total * 100) : 0; @endphp
<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    <div class="meter flex-1" role="img" aria-label="{{ __('heating.coverage.label', ['covered' => $covered, 'total' => $total]) }}">
        <span style="width: {{ $pct }}%"></span>
    </div>
    <span class="text-xs font-semibold tnum text-(--color-fg-muted)">{{ __('heating.coverage.short', ['covered' => $covered, 'total' => $total]) }}</span>
</div>

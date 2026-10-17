@props(['value', 'label', 'trend' => null])
<div class="card">
    <div class="tnum text-2xl font-bold">{{ $value }}</div>
    <div class="text-sm text-(--color-fg-muted)">{{ $label }}</div>
    @if ($trend)
        <div class="mt-1 text-xs">{{ $trend }}</div>
    @endif
</div>

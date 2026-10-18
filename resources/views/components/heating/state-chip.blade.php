@props(['state', 'tone' => null, 'size' => 'md'])
{{-- Heating/outage state chip: icon + word, never colour alone (DESIGN-SYSTEM §2.3). --}}
@php
    $icons = [
        'cold' => 'snowflake', 'lukewarm' => 'thermometer-snowflake', 'warm' => 'thermometer-sun', 'overheated' => 'flame',
        'mixed' => 'circle-alert', 'none' => 'circle-dashed', 'unconfirmed' => 'circle-help', 'disputed' => 'help-circle',
        'checked' => 'circle-check',
    ];
    $toneClass = $state === 'unconfirmed' && $tone ? ' heat-tone-'.$tone : '';
@endphp
<span {{ $attributes->merge(['class' => 'chip chip-heat-'.$state.$toneClass.($size === 'sm' ? ' text-xs' : '')]) }}>
    {{ svg('lucide-'.($icons[$state] ?? 'info'), 'size-4 shrink-0', ['aria-hidden' => 'true']) }}
    <span>{{ $slot->isEmpty() ? __('heating.state.'.$state) : $slot }}@if ($state === 'unconfirmed' && $tone) · {{ __('heating.state.'.$tone) }}@endif</span>
</span>

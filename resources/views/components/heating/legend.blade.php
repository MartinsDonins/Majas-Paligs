@props(['mode' => 'heating'])
{{-- Sticky collapsible legend (DESIGN-SYSTEM §6.3). --}}
<details class="card" {{ $attributes }}>
    <summary class="flex min-h-12 cursor-pointer items-center justify-between gap-2 font-semibold">
        <span class="flex min-w-0 items-center gap-2 [overflow-wrap:anywhere]"><x-lucide-info class="size-5 shrink-0 text-(--color-accent)" aria-hidden="true" /> {{ __('heating.legend.title') }}</span>
        <x-lucide-chevron-down class="size-5 shrink-0" aria-hidden="true" />
    </summary>
    @if ($mode === 'heating')
        <ul class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
            @foreach (['cold', 'lukewarm', 'warm', 'overheated', 'mixed', 'none', 'disputed'] as $s)
                <li class="flex flex-wrap items-center gap-x-2 gap-y-1 [&>*:first-child]:shrink-0"><x-heating.state-chip :state="$s" size="sm" /> <span class="muted min-w-0 grow basis-40 [overflow-wrap:anywhere]">{{ __('heating.legend.'.$s) }}</span></li>
            @endforeach
            <li class="flex flex-wrap items-center gap-x-2 gap-y-1 [&>*:first-child]:shrink-0"><x-heating.state-chip state="unconfirmed" tone="cold" size="sm">{{ __('heating.state.unconfirmed') }}</x-heating.state-chip> <span class="muted min-w-0 grow basis-40 [overflow-wrap:anywhere]">{{ __('heating.legend.unconfirmed') }}</span></li>
            <li class="flex flex-wrap items-center gap-x-2 gap-y-1 [&>*:first-child]:shrink-0"><x-heating.state-chip state="checked" size="sm" /> <span class="muted min-w-0 grow basis-40 [overflow-wrap:anywhere]">{{ __('heating.legend.checked') }}</span></li>
        </ul>
    @else
        <ul class="mt-3 grid gap-2 text-sm">
            @foreach (['water_cold' => 'droplet-off', 'water_hot' => 'droplet-off', 'heating_none' => 'heater'] as $kind => $icon)
                <li class="flex flex-wrap items-center gap-x-2 gap-y-1 [&>*:first-child]:shrink-0">
                    <x-dynamic-component :component="'lucide-'.$icon" class="size-5 outage-icon-{{ $kind }}" aria-hidden="true" />
                    <span>{{ __('enum.incident_kind.'.$kind) }}</span>
                </li>
            @endforeach
            <li class="flex flex-wrap items-center gap-x-2 gap-y-1 [&>*:first-child]:shrink-0"><span class="inline-block h-5 w-8 rounded outage-hit" aria-hidden="true"></span> <span class="muted min-w-0 grow basis-40 [overflow-wrap:anywhere]">{{ __('outage.legend.scope') }}</span></li>
        </ul>
    @endif
</details>

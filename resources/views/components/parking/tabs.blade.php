@props(['building', 'active' => 'lookup'])
{{-- Parking sub-navigation: Pārbaudīt · Manas caurlaides · Ziņot · Kampaņa (labels always visible, wraps 2×2 in RU). --}}
<nav class="segmented segmented-wrap" aria-label="{{ __('parking.tabs.label') }}">
    @foreach ([
        'lookup' => ['route' => 'building.parking', 'icon' => 'search'],
        'mine' => ['route' => 'building.parking.mine', 'icon' => 'badge-check'],
        'sighting' => ['route' => 'building.parking.sighting', 'icon' => 'flag'],
        'progress' => ['route' => 'building.parking.progress', 'icon' => 'chart-pie'],
    ] as $key => $tab)
        <a href="{{ route($tab['route'], $building) }}" wire:navigate @if ($active === $key) aria-current="page" @endif>
            <x-dynamic-component :component="'lucide-'.$tab['icon']" class="size-5" aria-hidden="true" /> {{ __('parking.tabs.'.$key) }}
        </a>
    @endforeach
</nav>

<x-filament-panels::page>
    @php($s = $this->summary())
    @php($apartments = $this->perApartment())

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-filament::section>
            <p class="text-3xl font-bold">{{ $s['completeness']['percent'] }}%</p>
            <p class="text-sm text-gray-500">{{ __('admin.parking.analytics.completeness', ['covered' => $s['completeness']['covered'], 'total' => $s['completeness']['total']]) }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-3xl font-bold">{{ $s['active_vehicles'] }}</p>
            <p class="text-sm text-gray-500">{{ __('admin.parking.analytics.active_vehicles', ['pending' => $s['pending_vehicles']]) }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-3xl font-bold">{{ $s['apartments_two_main'] }}</p>
            <p class="text-sm text-gray-500">{{ __('admin.parking.analytics.two_main') }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-3xl font-bold">{{ $s['unregistered_seen'] }}</p>
            <p class="text-sm text-gray-500">{{ __('admin.parking.analytics.unregistered_seen', ['days' => $s['seen_days']]) }}</p>
            <div class="mt-2 flex gap-1">
                @foreach ([3, 5, 10] as $d)
                    <x-filament::button size="xs" :color="$s['seen_days'] === $d ? 'primary' : 'gray'" wire:click="setSeenDays({{ $d }})">≥ {{ $d }}</x-filament::button>
                @endforeach
            </div>
        </x-filament::section>
    </div>

    <x-filament::section :heading="__('admin.parking.analytics.distribution')">
        <div class="grid gap-3 sm:grid-cols-4">
            @foreach ($s['permits_distribution'] as $bucket => $count)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <p class="text-2xl font-bold">{{ $count }}</p>
                    <p class="text-sm text-gray-500">{{ __('admin.parking.analytics.bucket', ['n' => $bucket]) }}</p>
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-sm text-gray-500">{{ __('admin.parking.analytics.guest', ['count' => $s['guest_permits']]) }}</p>
    </x-filament::section>

    <x-filament::section :heading="__('admin.parking.analytics.coverage')">
        <div class="grid gap-3 sm:grid-cols-5">
            @foreach ($s['sections'] as $row)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <p class="font-semibold">{{ __('admin.section.short', ['n' => $row['section']]) }}</p>
                    <p class="text-2xl font-bold">{{ $row['percent'] }}%</p>
                    <p class="text-xs text-gray-500">{{ $row['covered'] }} / {{ $row['total'] }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('admin.parking.analytics.per_apartment')" icon="heroicon-o-lock-closed">
        @if ($apartments === null)
            <p class="text-sm">{{ __('admin.parking.analytics.rules_needed') }}</p>
        @elseif ($apartments === [])
            <p class="text-sm">{{ __('admin.parking.analytics.none') }}</p>
        @else
            <p class="text-sm">{{ __('admin.parking.analytics.two_main_list') }}: <span class="font-mono">{{ implode(', ', $apartments) }}</span></p>
        @endif
    </x-filament::section>
</x-filament-panels::page>

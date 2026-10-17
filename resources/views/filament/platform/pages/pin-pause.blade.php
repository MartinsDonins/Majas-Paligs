<x-filament-panels::page>
    <x-filament::section :heading="__('admin.pin_pause.title')" :description="__('admin.pin_pause.help')">
        <div class="grid gap-2 text-sm">
            @foreach ($this->rows() as $row)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <span class="font-semibold">{{ $row['name'] }}</span>
                    <span>
                        @if ($row['paused'])
                            <x-filament::badge color="warning">{{ __('admin.pin_pause.manual', ['until' => \Carbon\CarbonImmutable::parse($row['until'])->timezone('Europe/Riga')->format('d.m.Y H:i')]) }}</x-filament::badge>
                        @elseif ($row['auto'])
                            <x-filament::badge color="warning">{{ __('admin.pin_pause.auto') }}</x-filament::badge>
                        @else
                            <x-filament::badge color="success">{{ __('admin.pin_pause.active') }}</x-filament::badge>
                        @endif
                    </span>
                    <span class="flex gap-2">
                        <x-filament::button size="sm" color="warning" wire:click="pause({{ $row['id'] }}, 1)">{{ __('admin.pin_pause.pause_1h') }}</x-filament::button>
                        <x-filament::button size="sm" color="warning" wire:click="pause({{ $row['id'] }}, 24)">{{ __('admin.pin_pause.pause_24h') }}</x-filament::button>
                        <x-filament::button size="sm" color="gray" wire:click="resume({{ $row['id'] }})">{{ __('admin.pin_pause.resume') }}</x-filament::button>
                    </span>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>

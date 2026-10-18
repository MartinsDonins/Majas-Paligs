<x-filament-panels::page>
    @php($gate = $this->gate())
    <x-filament::section :heading="__('admin.gate.title')" icon="heroicon-o-shield-check">
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ([1, 2] as $level)
                <div>
                    <p class="text-sm font-semibold">{{ __('admin.gate.l'.$level) }}</p>
                    @if ($gate->level >= $level)
                        <x-filament::badge color="success" icon="heroicon-o-check-circle">{{ __('admin.gate.open') }}</x-filament::badge>
                    @else
                        <x-filament::badge color="warning" icon="heroicon-o-lock-closed">{{ __('admin.gate.closed') }}</x-filament::badge>
                        <ul class="mt-2 list-disc ps-5 text-sm">
                            @foreach ($gate->missingLabels($level) as $label)
                                <li>{{ $label }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="mt-1 text-xs text-gray-500">{{ __('admin.gate.l'.$level.'_unlocks') }}</p>
                </div>
            @endforeach
        </div>
        @unless ($gate->l1())
            <p class="mt-4 text-sm">{{ __('admin.parking.prepare.l0_help') }}</p>
        @endunless
        @if ($gate->l2())
            <p class="mt-4 text-sm">{{ $gate->moduleOn ? __('admin.gate.parking_module_on') : __('admin.gate.parking_module_off') }}</p>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('admin.parking.sign.title')" icon="heroicon-o-document-text">
        <p class="text-sm">{{ __('admin.parking.sign.help') }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <x-filament::button tag="a" :href="$this->signUrl()" target="_blank" icon="heroicon-o-arrow-down-tray">{{ __('admin.parking.sign.download') }}</x-filament::button>
            @foreach ($this->lots() as $name)
                <x-filament::button tag="a" color="gray" :href="$this->signUrl($name)" target="_blank">{{ $name }}</x-filament::button>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>

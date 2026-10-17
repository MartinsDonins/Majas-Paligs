<x-filament-panels::page>
    @php($gate = $this->gateStatus())
    <x-filament::section :heading="__('admin.gate.title')" icon="heroicon-o-shield-check">
        <div class="grid gap-4 md:grid-cols-3">
            <div>
                <p class="text-sm font-semibold">{{ __('admin.gate.core') }}</p>
                @if ($gate->open)
                    <x-filament::badge color="success" icon="heroicon-o-check-circle">{{ __('admin.gate.open') }}</x-filament::badge>
                @else
                    <x-filament::badge color="warning" icon="heroicon-o-lock-closed">{{ __('admin.gate.closed') }}</x-filament::badge>
                    <ul class="mt-2 list-disc ps-5 text-sm">
                        @foreach ($gate->missingLabels() as $label)
                            <li>{{ $label }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <p class="text-sm font-semibold">{{ __('admin.gate.l1') }}</p>
                <x-filament::badge color="gray">{{ __('admin.gate.package_c') }}</x-filament::badge>
            </div>
            <div>
                <p class="text-sm font-semibold">{{ __('admin.gate.l2') }}</p>
                <x-filament::badge color="gray">{{ __('admin.gate.package_c') }}</x-filament::badge>
            </div>
        </div>
    </x-filament::section>

    <form wire:submit="save" class="grid gap-6">
        {{ $this->form }}
        <div>
            <x-filament::button type="submit" icon="heroicon-o-check">{{ __('common.save') }}</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>

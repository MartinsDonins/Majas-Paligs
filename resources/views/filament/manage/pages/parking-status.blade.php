{{-- Stāvvietas statuss (ROUND2-SPEC §3.7): L0 / L1 / L2 in plain words, what is missing (links to the wizard), what
     residents see. Status = icon + word. --}}
<x-filament-panels::page>
    @php
        $gate = $this->gate();
        $levelChip = match (true) {
            $gate->residentEnabled() => ['success', 'heroicon-o-check-circle', 'l2_on'],
            $gate->l2() => ['info', 'heroicon-o-pause-circle', 'l2_off'],
            $gate->l1() => ['info', 'heroicon-o-wrench-screwdriver', 'l1'],
            default => ['warning', 'heroicon-o-lock-closed', 'l0'],
        };
        $missing = $gate->l1() ? $gate->missingL2 : $gate->missingL1;
        $labels = $gate->l1() ? $gate->missingLabels(2) : $gate->missingLabels(1);
    @endphp

    <x-filament::section :heading="__('opening.parking_status.now')" icon="heroicon-o-signal">
        <div class="grid gap-3" data-testid="parking-status">
            <div><x-filament::badge :color="$levelChip[0]" :icon="$levelChip[1]">{{ __('opening.chip.'.$levelChip[2]) }}</x-filament::badge></div>
            <p class="text-sm" data-testid="parking-residents">
                {{ $gate->residentEnabled() ? __('opening.parking_status.residents_see') : __('opening.parking_status.residents_none') }}
            </p>
            @if ($gate->l1() && ! $gate->l2())
                <p class="text-sm" data-testid="parking-l1-note">{{ __('opening.dashboard.parking_l1') }}</p>
            @endif
            @if ($missing !== [])
                <div class="text-sm">
                    <p class="font-medium">{{ __('opening.dashboard.missing') }}</p>
                    <ul class="list-disc ps-5" data-testid="parking-missing">
                        @foreach ($missing as $i => $condition)
                            <li><a href="{{ $this->stepUrl($condition) }}" class="text-primary-600 underline">{{ $labels[$i] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @elseif ($gate->l2() && ! $gate->moduleOn)
                <p class="text-sm"><a href="{{ $this->wizardUrl() }}#step-parking_on" class="text-primary-600 underline">{{ __('opening.missing.parking_on') }}</a></p>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('opening.parking_status.levels')" icon="heroicon-o-information-circle" collapsible>
        <div class="grid gap-2 text-sm">
            <p><span class="font-semibold">L0.</span> {{ __('opening.parking_status.l0') }}</p>
            <p><span class="font-semibold">L1.</span> {{ __('opening.parking_status.l1') }}</p>
            <p><span class="font-semibold">L2.</span> {{ __('opening.parking_status.l2') }}</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>

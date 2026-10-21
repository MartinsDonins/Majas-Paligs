{{-- Gate cards (ROUND2-SPEC §3.7): status chip = icon + word, "Trūkst:" up to three items, "Turpināt" deep link. --}}
<x-filament-widgets::widget>
    @php
        $d = $this->data();
        $chips = [
            'open' => ['success', 'heroicon-o-check-circle'],
            'closed' => ['warning', 'heroicon-o-lock-closed'],
            'l0' => ['warning', 'heroicon-o-lock-closed'],
            'l1' => ['info', 'heroicon-o-wrench-screwdriver'],
            'l2_off' => ['info', 'heroicon-o-pause-circle'],
            'l2_on' => ['success', 'heroicon-o-check-circle'],
        ];
    @endphp
    <x-filament::section :heading="__('opening.dashboard.heading')" icon="heroicon-o-shield-check">
        <div class="grid gap-4 md:grid-cols-3" data-testid="gate-cards">
            @foreach ($d['cards'] as $gate => $card)
                @php [$color, $icon] = $chips[$card['chip']]; @endphp
                <div class="grid content-start gap-2 rounded-lg border border-gray-200 p-3 dark:border-white/10" data-testid="gate-{{ $gate }}">
                    <p class="text-sm font-semibold">{{ __('opening.gate.'.$gate) }}</p>
                    <div><x-filament::badge :color="$color" :icon="$icon">{{ __('opening.chip.'.$card['chip']) }}</x-filament::badge></div>
                    @if ($card['missing'] !== [])
                        <div class="text-sm">
                            <p class="font-medium">{{ __('opening.dashboard.missing') }}</p>
                            <ul class="list-disc ps-5" data-testid="gate-{{ $gate }}-missing">
                                @foreach ($card['missing'] as $label)
                                    <li>{{ $label }}</li>
                                @endforeach
                            </ul>
                            @if ($card['more'] > 0)
                                <p>{{ __('opening.dashboard.more', ['count' => $card['more']]) }}</p>
                            @endif
                        </div>
                    @endif
                    @if ($gate === 'parking' && $card['chip'] === 'l1')
                        <p class="text-sm">{{ __('opening.dashboard.parking_l1') }}</p>
                    @endif
                    @if ($d['buttons'] && $card['step'] !== null && (! $d['officerOnly'] || $gate === 'parking'))
                        <div>
                            <x-filament::button size="sm" tag="a" :href="$d['url'].'#step-'.$card['step']" icon="heroicon-o-arrow-right" data-testid="gate-{{ $gate }}-continue">
                                {{ __('opening.dashboard.continue') }}
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

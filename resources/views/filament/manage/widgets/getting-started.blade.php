{{-- "Sākt šeit" (FIRSTRUN-001): numbered steps, status = icon + word (never colour alone), one link per step. --}}
<x-filament-widgets::widget>
    @php
        $d = $this->data();
        $chips = [
            'done' => ['success', 'heroicon-o-check-circle'],
            'todo' => ['warning', 'heroicon-o-arrow-right-circle'],
            'blocked' => ['gray', 'heroicon-o-lock-closed'],
            'optional' => ['info', 'heroicon-o-minus-circle'],
        ];
    @endphp
    <x-filament::section :heading="__('start.steps.heading')" :description="__('start.steps.progress', $d['progress'])" icon="heroicon-o-rocket-launch" collapsible>
        <ol class="grid gap-3" data-testid="getting-started">
            @foreach ($d['steps'] as $i => $step)
                @php [$color, $icon] = $chips[$step['status']]; @endphp
                <li class="grid gap-2 rounded-lg border border-gray-200 p-3 sm:grid-cols-[1fr_auto] sm:items-center dark:border-white/10"
                    data-testid="step-{{ $step['key'] }}" data-status="{{ $step['status'] }}">
                    <div class="grid gap-1">
                        <p class="text-sm font-semibold">{{ $i + 1 }}. {{ __('start.steps.'.$step['key'].'.title') }}</p>
                        <div><x-filament::badge :color="$color" :icon="$icon">{{ __('start.steps.status.'.$step['status']) }}</x-filament::badge></div>
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $step['detail'] }}</p>
                    </div>
                    @if ($step['status'] !== 'done' || in_array($step['key'], ['team', 'roster', 'permits', 'letters'], true))
                        <div>
                            <x-filament::button size="sm" tag="a" :href="$step['url']" :color="$step['status'] === 'done' ? 'gray' : 'primary'" icon="heroicon-o-arrow-right" data-testid="step-{{ $step['key'] }}-link">
                                {{ __('start.steps.action.'.$step['action']) }}
                            </x-filament::button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    </x-filament::section>
</x-filament-widgets::widget>

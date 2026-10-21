{{-- Atvēršanas pieprasījumi (ROUND2-SPEC §3.6): stacked cards, building + kind + requested scope + PDF preview. --}}
<x-filament-panels::page>
    @php($rows = $this->rows())

    @if ($rows === [])
        <p class="text-sm" data-testid="requests-empty">{{ __('opening.requests.empty') }}</p>
    @else
        <ul class="grid gap-3" data-testid="opening-requests">
            @foreach ($rows as $r)
                <li class="rounded-lg border border-gray-200 p-3 dark:border-white/10" wire:key="req-{{ $r['id'] }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::badge color="info" icon="heroicon-o-clock">{{ __('opening.status.waiting') }}</x-filament::badge>
                        <span class="font-semibold">{{ $r['building'] }}</span>
                        <span class="text-sm">{{ $r['kind']->label() }} · v{{ $r['version'] }}</span>
                    </div>
                    <p class="mt-1 text-sm">{{ __('opening.requests.scope') }}: {{ collect($r['scope'])->map(fn ($m) => __('enum.module.'.$m))->join(', ') }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('opening.requests.sent', ['date' => $r['sent']]) }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        {{ ($this->recordAction)(['building' => $r['building_id'], 'document' => $r['id'], 'scope' => $r['scope']]) }}
                        @foreach (['lv', 'ru'] as $loc)
                            <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="pdf({{ $r['building_id'] }}, '{{ $r['id'] }}', '{{ $loc }}')">PDF {{ strtoupper($loc) }}</x-filament::button>
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>

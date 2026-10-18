<div>
    @if ($round)
        @php $pct = $total > 0 ? (int) round($filled / $total * 100) : 0; @endphp
        <section class="card stack" aria-labelledby="round-h">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="round-h" class="text-lg">{{ $round->title }}</h2>
                    <p class="text-sm muted">{{ __('heating.round.until', ['date' => $round->ends_at->setTimezone('Europe/Riga')->format('d.m.Y')]) }}</p>
                </div>
                <x-lucide-clipboard-list class="size-7 shrink-0 text-(--color-accent)" aria-hidden="true" />
            </div>
            <div class="space-y-1">
                <div class="meter" role="img" aria-label="{{ __('heating.round.progress', ['filled' => $filled, 'total' => $total]) }}"><span style="width: {{ $pct }}%"></span></div>
                <p class="text-sm font-semibold tnum">{{ __('heating.round.progress', ['filled' => $filled, 'total' => $total]) }}</p>
            </div>
            @if ($mine)
                <p class="text-sm flex items-center gap-2"><x-lucide-circle-check class="size-4 text-(--color-success)" aria-hidden="true" /> {{ __('heating.round.done') }}</p>
            @elseif ($canFill)
                <a href="{{ route('building.heating', $building) }}?k={{ $section }}&r={{ $round->roundKey() }}&report=1" class="btn btn-primary btn-block" wire:navigate>
                    {{ __('heating.round.fill') }}
                </a>
            @endif
        </section>
    @endif
</div>

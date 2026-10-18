<div wire:poll.{{ $pollSeconds }}s>
    @if ($enabled && $active->isNotEmpty())
        <section class="stack" aria-label="{{ __('outage.banner.label') }}">
            @foreach ($active as $incident)
                <a href="{{ route('building.incident', [$building, $incident->id]) }}" wire:key="ob-{{ $incident->id }}"
                   class="banner banner-danger no-underline text-(--color-fg)" role="status">
                    <span class="pulse-dot" aria-hidden="true"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-bold">
                            {{ $incident->origin->value === 'notice' ? __('outage.banner.notice', ['kind' => $incident->kind->label()]) : __('outage.banner.active', ['kind' => $incident->kind->label()]) }}
                        </span>
                        <span class="block text-sm">
                            {{ app(\App\Services\Outages\IncidentPresenter::class)->scope($incident) }}
                            · {{ \App\Support\Copy::count('outage.reports', (int) $incident->confirmations_count, ['count' => (int) $incident->confirmations_count]) }}
                            · {{ $incident->started_at->locale(app()->getLocale())->diffForHumans() }}
                        </span>
                        @if ($incident->origin->value === 'notice' && $incident->getTranslation('reason', app()->getLocale()))
                            <span class="block text-sm">{{ $incident->getTranslation('reason', app()->getLocale()) }}</span>
                        @endif
                        @if ($incident->expected_end_at)
                            <span class="block text-sm font-semibold">{{ __('outage.banner.expected', ['time' => $incident->expected_end_at->setTimezone('Europe/Riga')->format('d.m. H:i')]) }}</span>
                        @endif
                    </span>
                    <x-lucide-chevron-right class="size-5 shrink-0" aria-hidden="true" />
                </a>
            @endforeach
            @if ($more > 0)
                <p class="text-sm muted">{{ \App\Support\Copy::count('outage.banner.more', $more, ['count' => $more]) }}</p>
            @endif
        </section>
    @elseif ($calmLine)
        <div class="banner" role="status">
            <x-lucide-circle-check class="mt-0.5 size-5 shrink-0 text-(--color-success)" aria-hidden="true" />
            <p>
                {{ __('home.status_calm') }}
                @if ($lastDays !== null) <span class="muted">· {{ \App\Support\Copy::count('outage.banner.last', $lastDays, ['count' => $lastDays]) }}</span> @endif
            </p>
        </div>
    @endif
</div>

<div class="stack-lg" wire:poll.visible.30s>
    <a href="{{ route('building.water', $building) }}" class="btn-link inline-flex items-center gap-1" wire:navigate>
        <x-lucide-chevron-left class="size-4" aria-hidden="true" /> {{ __('common.back') }}
    </a>

    <section class="card stack" aria-labelledby="incident-h">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-center gap-3">
                <x-dynamic-component :component="'lucide-'.$incident->kind->icon()" class="size-8 outage-icon-{{ $incident->kind->value }}" aria-hidden="true" />
                <div>
                    <h1 id="incident-h" class="text-2xl">{{ $incident->kind->label() }}</h1>
                    <p class="text-sm muted">{{ $scope }}</p>
                </div>
            </div>
            <span class="chip {{ $incident->is_open ? 'chip-warning' : 'chip-success' }}">
                <x-dynamic-component :component="$incident->is_open ? 'lucide-clock' : 'lucide-circle-check'" class="size-4" aria-hidden="true" />
                {{ $incident->status->label() }}
            </span>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt class="muted">{{ __('outage.detail.since') }}</dt>
            <dd class="font-semibold tnum">{{ $incident->started_at->setTimezone('Europe/Riga')->format('d.m.Y H:i') }}</dd>
            @if ($incident->expected_end_at)
                <dt class="muted">{{ __('outage.detail.expected') }}</dt>
                <dd class="font-semibold tnum">{{ $incident->expected_end_at->setTimezone('Europe/Riga')->format('d.m.Y H:i') }}</dd>
            @endif
            @if ($incident->resolved_at)
                <dt class="muted">{{ __('outage.detail.resolved') }}</dt>
                <dd class="font-semibold tnum">{{ $incident->resolved_at->setTimezone('Europe/Riga')->format('d.m.Y H:i') }}</dd>
            @endif
            <dt class="muted">{{ __('outage.detail.reports') }}</dt>
            <dd class="font-semibold">{{ trans_choice('outage.reports', (int) $incident->confirmations_count, ['count' => (int) $incident->confirmations_count]) }}</dd>
        </dl>

        @if ($reason)
            <x-ui.banner variant="info" icon="megaphone">
                <p class="font-semibold">{{ __('outage.detail.notice') }}</p>
                <p>{{ $reason }}</p>
            </x-ui.banner>
        @endif

        @if ($reply)
            <x-ui.banner variant="info" icon="message-circle">
                <p><span class="font-semibold">{{ $building->manager_name ? __('outage.detail.reply_from', ['manager' => $building->manager_name]) : __('outage.detail.reply') }}</span> {{ $reply }}</p>
            </x-ui.banner>
        @endif

        @if ($incident->is_open)
            <livewire:outage.me-too-button :incident-id="$incident->id" :key="'me-too-'.$incident->id" />
        @endif
    </section>

    <div class="banner text-sm">
        <x-lucide-siren class="size-5 shrink-0 text-(--color-danger)" aria-hidden="true" />
        <div>
            <p class="font-semibold">{{ __('shell.not_emergency') }}</p>
            @if ($building->manager_emergency_phone)
                <p>{{ __('shell.emergency_phone') }}: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $building->manager_emergency_phone) }}" class="font-bold tnum">{{ $building->manager_emergency_phone }}</a></p>
            @else
                <p>{{ __('shell.emergency_phone_missing') }}</p>
            @endif
        </div>
    </div>

    <x-ui.privacy-note>{{ __('outage.detail.privacy') }}</x-ui.privacy-note>
</div>

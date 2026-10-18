<div class="space-y-4">
    @if (! $canReport)
        <x-ui.banner variant="info" icon="clock">{{ __('heating.pending_read_only') }}</x-ui.banner>
    @elseif ($apartments !== null)
        <div class="space-y-4 text-center" role="status">
            <x-lucide-users-round class="mx-auto size-12 text-(--color-accent)" aria-hidden="true" />
            <p class="text-xl font-bold">{{ \App\Support\Copy::count('outage.report.joined', $apartments, ['count' => $apartments]) }}</p>
            <p>{{ __($joinedNotice ? 'outage.report.notice_known' : 'outage.report.not_only_you') }}</p>
            @foreach ($incidentIds as $id)
                <a href="{{ route('building.incident', [$building, $id]) }}" class="btn btn-block" wire:navigate>{{ __('outage.report.see_status') }}</a>
            @endforeach
            <button type="button" wire:click="again" class="btn btn-primary btn-block">{{ __('heating.report.ok') }}</button>
        </div>
    @else
        <form wire:submit="submit" class="space-y-4">
            <fieldset class="space-y-2">
                <legend class="field-label">{{ __('outage.report.what') }}</legend>
                @foreach ($kinds as $k)
                    <button type="button" wire:click="choose('{{ $k->value }}')" class="choice" aria-pressed="{{ $kind === $k->value ? 'true' : 'false' }}">
                        <x-dynamic-component :component="'lucide-'.$k->icon()" class="size-6 outage-icon-{{ $k->value }}" aria-hidden="true" />
                        {{ __('outage.report.kind.'.$k->value) }}
                    </button>
                @endforeach
                @error('kind') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </fieldset>

            @if ($kind)
                <fieldset class="space-y-2">
                    <legend class="field-label">{{ __('outage.report.since') }}</legend>
                    <div class="segmented">
                        @foreach (['now', 'hour', 'morning'] as $s)
                            <button type="button" wire:click="setSince('{{ $s }}')" aria-pressed="{{ $since === $s ? 'true' : 'false' }}">{{ __('outage.report.since_'.$s) }}</button>
                        @endforeach
                    </div>
                </fieldset>

                @if ($options !== [])
                    <fieldset class="space-y-2">
                        <legend class="field-label">{{ __('outage.report.where.'.$kind) }}</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($options as $o)
                                <label class="choice min-h-12">
                                    <input type="radio" name="answer" value="{{ $o['key'] }}" wire:model="answer" class="size-5">
                                    <span>{{ $o['label'] }}</span>
                                </label>
                            @endforeach
                            <label class="choice min-h-12">
                                <input type="radio" name="answer" value="all" wire:model="answer" class="size-5">
                                <span>{{ __('outage.report.everywhere') }}</span>
                            </label>
                        </div>
                        @error('answer') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                    </fieldset>
                @endif

                <div x-data="connectivity">
                    <div x-show="offline" x-cloak class="banner banner-warning mb-3" role="status">
                        <x-lucide-wifi-off class="size-5 shrink-0" aria-hidden="true" /> <span>{{ __('shell.offline') }}</span>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">
                        <x-lucide-send class="size-5" aria-hidden="true" /> {{ __('outage.report.submit') }}
                    </button>
                </div>
            @endif
        </form>
    @endif

    <div class="banner text-sm">
        <x-lucide-siren class="size-5 shrink-0 text-(--color-danger)" aria-hidden="true" />
        <div>
            <p class="font-semibold">{{ __('shell.not_emergency') }}</p>
            @if ($building->manager_emergency_phone)
                <p>{{ __('shell.emergency_phone') }}: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $building->manager_emergency_phone) }}" class="font-bold tnum">{{ $building->manager_emergency_phone }}</a></p>
            @endif
        </div>
    </div>
</div>

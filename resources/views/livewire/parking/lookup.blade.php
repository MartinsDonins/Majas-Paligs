<div class="stack-lg">
    <section class="stack">
        <h1 class="page-title">{{ __('parking.lookup.title') }}</h1>
        <x-parking.tabs :building="$building" active="lookup" />
        <livewire:parking.legal-banner />
    </section>

    @if (! $canLookup)
        <x-ui.banner variant="info" icon="clock">{{ __('parking.pending_read_only') }}</x-ui.banner>
    @elseif ($registered !== null)
        {{-- The ONLY registry datum a resident gets: yes / no. Never an apartment, permit type or history. --}}
        <section class="lookup-result" data-result="{{ $registered ? 'yes' : 'no' }}" role="status" aria-live="polite">
            @if ($registered)
                <x-lucide-circle-check class="lookup-icon size-14" aria-hidden="true" />
                <p class="lookup-word">{{ __('parking.lookup.registered') }}</p>
            @else
                <x-lucide-info class="lookup-icon size-14" aria-hidden="true" />
                <p class="lookup-word">{{ __('parking.lookup.not_found') }}</p>
                <p class="muted">{{ __('parking.lookup.not_found_hint') }}</p>
            @endif
            <p class="text-sm muted tnum">{{ __('parking.lookup.completeness', ['percent' => $completeness['percent']]) }}</p>
            <p class="flex items-center gap-2 text-sm muted">
                <x-lucide-lock class="size-4" aria-hidden="true" /> {{ __('parking.lookup.logged') }}
            </p>
        </section>
        <div class="grid gap-3 sm:grid-cols-2">
            @unless ($registered)
                <a href="{{ route('building.parking.sighting', $building) }}" class="btn btn-block" wire:navigate>
                    <x-lucide-flag class="size-5" aria-hidden="true" /> {{ __('parking.lookup.report') }}
                </a>
            @endunless
            <button type="button" wire:click="again" class="btn btn-primary btn-block">
                <x-lucide-search class="size-5" aria-hidden="true" /> {{ __('parking.lookup.again') }}
            </button>
        </div>
    @else
        <section class="card stack" aria-labelledby="lookup-form-h">
            <h2 id="lookup-form-h" class="text-lg">{{ __('parking.lookup.question') }}</h2>

            @if ($retryAfter)
                <x-parking.retry :seconds="$retryAfter" wire:key="retry-{{ $retryAfter }}" />
            @endif

            <form x-data="plateForm" data-method="check" x-on:submit="submit" class="stack" novalidate>
                <x-ui.plate-input id="lookup-plate" :error="$error === 'invalid' ? __('parking.plate.invalid') : null" />

                <fieldset>
                    <legend class="field-label">{{ __('parking.lookup.reason') }}</legend>
                    <div class="reason-chips">
                        @foreach ($reasons as $r)
                            <button type="button" wire:click="setReason('{{ $r }}')" aria-pressed="{{ $reason === $r ? 'true' : 'false' }}">
                                {{ __('enum.lookup_reason.'.$r) }}
                            </button>
                        @endforeach
                    </div>
                </fieldset>

                <div x-data="connectivity">
                    <div x-show="offline" x-cloak class="banner banner-warning mb-3" role="status">
                        <x-lucide-wifi-off class="size-5 shrink-0" aria-hidden="true" /> <span>{{ __('shell.offline') }}</span>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">
                        <x-lucide-search class="size-5" aria-hidden="true" /> {{ __('parking.lookup.submit') }}
                    </button>
                </div>
            </form>

            <p class="flex items-start gap-2 text-sm muted">
                <x-lucide-lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ __('parking.lookup.logged_hint') }}
            </p>
        </section>

        <div class="banner text-sm" role="note">
            <x-lucide-list-checks class="size-5 shrink-0 text-(--color-accent)" aria-hidden="true" />
            <p class="tnum">{{ __('parking.lookup.completeness', ['percent' => $completeness['percent']]) }}
                <a href="{{ route('building.parking.progress', $building) }}" wire:navigate>{{ __('parking.lookup.completeness_more') }}</a></p>
        </div>
    @endif

    <livewire:parking.privacy-box context="lookup" />
</div>

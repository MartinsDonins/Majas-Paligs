<div class="stack-lg">
    <section class="stack">
        <h1 class="page-title">{{ __('parking.declare.title') }}</h1>
        <p class="lead">{{ __('parking.declare.lead') }}</p>
    </section>

    @if ($amnestyUntil)
        <x-ui.banner variant="info" icon="party-popper">
            <p class="font-semibold">{{ __('parking.declare.amnesty_title', ['date' => $amnestyUntil->format('d.m.Y')]) }}</p>
            <p class="text-sm">{{ __('parking.declare.amnesty_body') }}</p>
        </x-ui.banner>
    @endif

    @if (! $canDeclare)
        <x-ui.banner variant="info" icon="clock">{{ __('parking.pending_read_only') }}</x-ui.banner>
    @elseif ($step === 'done')
        <section class="card stack text-center" role="status">
            <x-lucide-clock class="mx-auto size-12 text-(--color-accent)" aria-hidden="true" />
            <p class="text-xl font-bold">{{ __('parking.declare.thanks') }}</p>
            <p class="muted">{{ __('parking.declare.thanks_body') }}</p>
            <div class="grid gap-2 sm:grid-cols-2">
                <button type="button" wire:click="another" class="btn btn-block"><x-lucide-plus class="size-5" aria-hidden="true" /> {{ __('parking.declare.another') }}</button>
                <a href="{{ route('building.parking.mine', $building) }}" class="btn btn-primary btn-block" wire:navigate>{{ __('parking.declare.to_mine') }}</a>
            </div>
        </section>
    @elseif ($step === 'no_car_done')
        <section class="card stack text-center" role="status">
            <x-lucide-circle-check class="mx-auto size-12 text-(--color-success)" aria-hidden="true" />
            <p class="text-xl font-bold">{{ __('parking.declare.no_car_thanks') }}</p>
            <a href="{{ route('building.parking.progress', $building) }}" class="btn btn-primary btn-block" wire:navigate>{{ __('parking.progress.title') }}</a>
        </section>
    @elseif ($step === 'car')
        <section class="card stack" aria-labelledby="car-h">
            <h2 id="car-h" class="text-lg">{{ __('parking.declare.car_title') }}</h2>

            @if ($retryAfter)
                <x-parking.retry :seconds="$retryAfter" />
            @endif

            <form x-data="plateForm" data-method="declareCar" x-on:submit="submit" class="stack" novalidate>
                <x-ui.plate-input id="declare-plate" :error="$error === 'invalid' ? __('parking.plate.invalid') : null" />

                <x-ui.field id="declare-country" :label="__('parking.plate.country')">
                    <select id="declare-country" wire:model="country" class="input">
                        @foreach (['LV', 'LT', 'EE', 'PL', 'FI', 'DE', 'UA'] as $c)
                            <option value="{{ $c }}">{{ __('parking.country.'.$c) }}</option>
                        @endforeach
                    </select>
                </x-ui.field>

                <label class="choice min-h-14">
                    <input type="checkbox" wire:model="company" class="size-6">
                    <span>{{ __('parking.declare.company') }}</span>
                </label>

                <livewire:parking.privacy-box context="plate" />

                <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">
                    <x-lucide-send class="size-5" aria-hidden="true" /> {{ __('parking.declare.submit') }}
                </button>
            </form>
            <button type="button" wire:click="choose('back')" class="btn-link">{{ __('common.back') }}</button>
        </section>
    @else
        <section class="stack" aria-label="{{ __('parking.declare.choose') }}">
            <button type="button" wire:click="choose('car')" class="choice">
                <x-lucide-car class="size-7 text-(--color-accent)" aria-hidden="true" />
                <span>{{ __('parking.declare.have_car') }}</span>
            </button>
            <button type="button" wire:click="declareNoCar" class="choice">
                <x-lucide-footprints class="size-7 text-(--color-accent)" aria-hidden="true" />
                <span>{{ __('parking.declare.no_car') }}</span>
            </button>
            @if ($error === 'has_vehicles')
                <p class="field-error" role="alert"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ __('parking.declare.has_vehicles') }}</p>
            @endif
        </section>
        <p class="text-sm muted">{{ __('parking.mine.elder_alternative') }}</p>
    @endif
</div>

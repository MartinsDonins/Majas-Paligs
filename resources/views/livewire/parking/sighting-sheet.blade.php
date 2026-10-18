<div class="stack-lg">
    <section class="stack">
        <h1 class="page-title">{{ __('parking.sighting.title') }}</h1>
        <x-parking.tabs :building="$building" active="sighting" />
    </section>

    @if (! $canReport)
        <x-ui.banner variant="info" icon="clock">{{ __('parking.pending_read_only') }}</x-ui.banner>
    @elseif ($lots->isEmpty())
        <x-ui.empty-state :title="__('parking.sighting.no_lots')" />
    @elseif ($state === 'submitted')
        {{-- Always the same neutral confirmation — never "reģistrēts" / "nav reģistrēts". --}}
        <section class="card stack text-center" role="status">
            <x-lucide-send class="mx-auto size-12 text-(--color-accent)" aria-hidden="true" />
            <p class="text-xl font-bold">{{ __('parking.sighting.thanks') }}</p>
            @if ($photoDropped)
                <p class="muted">{{ __('parking.sighting.photo_unavailable') }}</p>
            @endif
            <button type="button" wire:click="again" class="btn btn-primary btn-block">{{ __('heating.report.ok') }}</button>
        </section>
    @elseif ($state === 'limit')
        <x-ui.banner variant="warning" icon="hand">{{ __('parking.sighting.limit') }}</x-ui.banner>
    @else
        <section class="card stack" aria-labelledby="sighting-h">
            <h2 id="sighting-h" class="sr-only">{{ __('parking.sighting.title') }}</h2>

            @if ($retryAfter)
                <x-parking.retry :seconds="$retryAfter" />
            @endif

            <form x-data="plateForm" data-method="submit" data-keep="1" x-on:submit="submit" class="stack" novalidate>
                <x-ui.field id="sighting-lot" :label="__('parking.sighting.lot')">
                    <select id="sighting-lot" wire:model="lotId" class="input">
                        @foreach ($lots as $lot)
                            <option value="{{ $lot->id }}">{{ $lot->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>

                <x-ui.field id="sighting-time" :label="__('parking.sighting.time')">
                    <input id="sighting-time" type="datetime-local" wire:model="seenAt" class="input tnum" max="{{ now('Europe/Riga')->addMinutes(5)->format('Y-m-d\TH:i') }}">
                </x-ui.field>

                <x-ui.plate-input id="sighting-plate" :error="$state === 'invalid' ? __('parking.plate.invalid') : null" />

                <div x-data="photoCapture" class="stack">
                    <p class="field-label">{{ __('parking.sighting.photo') }}</p>
                    <input type="file" accept="image/*" capture="environment" class="sr-only" x-ref="camera" x-on:change="onChange" tabindex="-1">
                    <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" class="sr-only" x-ref="file" x-on:change="onChange" tabindex="-1">
                    @if ($photo)
                        <div class="banner" role="status">
                            <x-lucide-image class="size-5 shrink-0 text-(--color-accent)" aria-hidden="true" />
                            <p class="flex-1">{{ __('parking.sighting.photo_ready') }}</p>
                            <button type="button" wire:click="removePhoto" class="btn">{{ __('parking.sighting.photo_remove') }}</button>
                        </div>
                    @else
                        <div class="grid gap-2 sm:grid-cols-2">
                            <button type="button" x-on:click="pickCamera" class="btn btn-block"><x-lucide-camera class="size-5" aria-hidden="true" /> {{ __('parking.sighting.photo_take') }}</button>
                            <button type="button" x-on:click="pickFile" class="btn btn-block"><x-lucide-image-plus class="size-5" aria-hidden="true" /> {{ __('parking.sighting.photo_pick') }}</button>
                        </div>
                    @endif
                    <p x-show="isWorking" x-cloak class="text-sm muted" role="status">{{ __('parking.sighting.photo_working') }}</p>
                    <p x-show="isError" x-cloak class="field-error" role="alert">{{ __('parking.sighting.photo_error.format') }}</p>
                    @if ($photoError)
                        <p class="field-error" role="alert"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ __('parking.sighting.photo_error.'.$photoError) }}</p>
                    @endif
                    <p class="text-sm muted">{{ __('parking.sighting.photo_rules') }}</p>
                </div>

                <livewire:parking.privacy-box context="sighting" />

                <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">
                    <x-lucide-send class="size-5" aria-hidden="true" /> {{ __('parking.sighting.submit') }}
                </button>
            </form>
        </section>
    @endif

    @if ($mine->isNotEmpty())
        <section class="card stack" aria-labelledby="my-sightings-h">
            <h2 id="my-sightings-h" class="text-lg">{{ __('parking.sighting.mine') }}</h2>
            <ul>
                @foreach ($mine as $s)
                    <li class="list-row">
                        <span>
                            <span class="block font-semibold">{{ $s->lot?->name }}</span>
                            <time class="text-sm muted tnum" datetime="{{ $s->seen_at->toIso8601String() }}">{{ $s->seen_at->timezone('Europe/Riga')->format('d.m.Y H:i') }}</time>
                        </span>
                        <span class="chip text-xs">{{ __('parking.sighting.state.'.$s->reporterState()) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

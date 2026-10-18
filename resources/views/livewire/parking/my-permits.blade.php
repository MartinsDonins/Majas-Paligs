<div class="stack-lg">
    <section class="stack">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <h1 class="page-title">{{ __('parking.mine.title') }}</h1>
            <span class="chip tnum">{{ __('parking.mine.counts', ['main' => $mainCount, 'guest' => $guestCount]) }}</span>
        </div>
        <x-parking.tabs :building="$building" active="mine" />
    </section>

    @if ($flash)
        <x-ui.banner variant="info" icon="circle-check">{{ __('parking.mine.flash.'.$flash) }}</x-ui.banner>
    @endif

    @if ($apartment === null)
        <x-ui.banner variant="info" icon="clock">{{ __('parking.pending_read_only') }}</x-ui.banner>
    @else
        {{-- Permits --}}
        <section class="stack" aria-labelledby="permits-h">
            <h2 id="permits-h" class="text-lg">{{ __('parking.mine.permits') }}</h2>
            @forelse ($permits as $permit)
                @php($state = $permit->displayState())
                <article class="card permit-card stack" data-type="{{ $permit->type->value }}" wire:key="permit-{{ $permit->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="chip {{ $permit->type->value === 'main' ? 'chip-accent' : '' }}">
                            <x-dynamic-component :component="'lucide-'.($permit->type->value === 'main' ? 'badge-check' : 'users')" class="size-4" aria-hidden="true" />
                            {{ $permit->type->label() }}
                        </span>
                        <span class="chip {{ $state === 'expiring' ? 'chip-warning' : ($state === 'active' ? 'chip-success' : '') }}">
                            <x-dynamic-component :component="'lucide-'.($state === 'expiring' ? 'clock-alert' : ($state === 'active' ? 'circle-check' : 'circle-minus'))" class="size-4" aria-hidden="true" />
                            {{ __('parking.permit_state.'.$state) }}
                        </span>
                    </div>
                    <p class="text-2xl font-bold tnum">{{ __('parking.mine.permit_no', ['number' => $permit->number]) }}</p>
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($permit->type->value === 'main' && $permit->vehicle_id && $vehicles->has($permit->vehicle_id))
                            <x-ui.plate-badge :plate="$vehicles[$permit->vehicle_id]['plate']" :country="$vehicles[$permit->vehicle_id]['country']" />
                        @elseif ($permit->type->value === 'guest')
                            <span class="text-sm muted">{{ __('parking.mine.guest_not_bound') }}</span>
                        @endif
                        @if ($permit->valid_to)
                            <span class="text-sm tnum">{{ __('parking.mine.valid_to', ['date' => $permit->valid_to->format('d.m.Y')]) }}</span>
                        @endif
                    </div>
                    @if ($permit->type->value === 'main' && $canDeclare)
                        <x-ui.bottom-sheet id="change-{{ $permit->id }}" :title="__('parking.change.title')">
                            <x-slot:trigger>
                                <button type="button" x-on:click="show" class="btn btn-block">
                                    <x-lucide-repeat class="size-5" aria-hidden="true" /> {{ __('parking.change.open') }}
                                </button>
                            </x-slot:trigger>
                            <livewire:parking.change-vehicle-wizard :permit-id="$permit->id" :key="'cvw-'.$permit->id" />
                        </x-ui.bottom-sheet>
                    @endif
                </article>
            @empty
                <x-ui.empty-state :title="__('parking.mine.no_permits')">
                    <p class="text-sm muted">{{ __('parking.mine.no_permits_rule') }}</p>
                </x-ui.empty-state>
            @endforelse
        </section>

        {{-- Vehicles of the apartment (own plates only) --}}
        <section class="card stack" aria-labelledby="vehicles-h">
            <div class="flex items-center justify-between gap-2">
                <h2 id="vehicles-h" class="text-lg">{{ __('parking.mine.vehicles') }}</h2>
                @if ($canDeclare)
                    <a href="{{ route('building.parking.declare', $building) }}" class="btn" wire:navigate>
                        <x-lucide-plus class="size-5" aria-hidden="true" /> {{ __('parking.mine.add') }}
                    </a>
                @endif
            </div>

            @forelse ($vehicles as $v)
                <div class="list-row flex-wrap items-start" wire:key="veh-{{ $v['id'] }}">
                    <div class="flex flex-col gap-2">
                        <x-ui.plate-badge :plate="$v['plate']" :country="$v['country']" />
                        <div class="flex flex-wrap gap-2">
                            @if ($v['pending'])
                                <x-ui.status-chip status="pending">{{ __('parking.vehicle.pending') }}</x-ui.status-chip>
                            @else
                                <x-ui.status-chip status="approved">{{ __('parking.vehicle.active') }}</x-ui.status-chip>
                            @endif
                            @if ($v['company'])
                                <span class="chip text-xs"><x-lucide-briefcase class="size-4" aria-hidden="true" /> {{ __('parking.vehicle.company') }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($v['reconfirm'])
                            <button type="button" wire:click="reconfirm('{{ $v['id'] }}')" class="btn">
                                <x-lucide-circle-check class="size-5" aria-hidden="true" /> {{ __('parking.vehicle.reconfirm_yes') }}
                            </button>
                        @endif
                        <button type="button" wire:click="askRemove('{{ $v['id'] }}')" class="btn btn-danger">
                            <x-lucide-trash-2 class="size-5" aria-hidden="true" /> {{ __('parking.vehicle.remove') }}
                        </button>
                    </div>
                    @if ($v['reconfirm'])
                        <p class="w-full text-sm">{{ __('parking.vehicle.reconfirm_question') }}</p>
                    @endif
                </div>

                @if ($confirmRemove === $v['id'])
                    <div class="banner banner-danger" role="alertdialog" aria-labelledby="rm-{{ $v['id'] }}-h">
                        <x-lucide-triangle-alert class="mt-0.5 size-5 shrink-0 text-(--color-danger)" aria-hidden="true" />
                        <div class="stack min-w-0 flex-1">
                            <p id="rm-{{ $v['id'] }}-h" class="font-semibold">{{ __('parking.vehicle.remove_confirm', ['plate' => $mask($v['plate'])]) }}</p>
                            @if ($boundPermit)
                                <p>{{ __('parking.vehicle.remove_bound', ['number' => $boundPermit->number]) }}</p>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <a href="#change-{{ $boundPermit->id }}-h" class="btn btn-primary" wire:click="cancelRemove">{{ __('parking.change.open') }}</a>
                                    <button type="button" wire:click="remove" class="btn btn-danger">{{ __('parking.vehicle.remove_anyway') }}</button>
                                </div>
                            @else
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <button type="button" wire:click="remove" class="btn btn-danger">{{ __('parking.vehicle.remove_yes') }}</button>
                                    <button type="button" wire:click="cancelRemove" class="btn">{{ __('common.cancel') }}</button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @empty
                <p class="muted">{{ __('parking.mine.no_vehicles') }}</p>
            @endforelse

            <p class="text-sm muted">{{ __('parking.mine.elder_alternative') }}</p>
        </section>

        <section class="card stack" aria-labelledby="guest-h">
            <h2 id="guest-h" class="text-lg">{{ __('parking.dispute.title') }}</h2>
            <livewire:parking.sighting-dispute />
        </section>
    @endif

    <livewire:parking.privacy-box context="plate" />
</div>

<div class="stack-lg">
    @if (session('status') === 'joined' || $membership?->isPending())
        <x-onboarding.pending-note />
    @endif

    <section aria-labelledby="home-h" class="stack">
        <h1 id="home-h" class="sr-only">{{ __('nav.home') }}</h1>
        @if (! $houseOpen)
            {{-- FIRSTRUN-001: a house that is not open yet never says "Šobrīd viss kārtībā". --}}
            <x-ui.banner variant="warning" icon="lock" data-testid="house-closed">
                <p class="font-semibold">{{ __('start.home.closed_title', ['done' => $coreProgress['done'], 'total' => $coreProgress['total']]) }}</p>
                <p class="text-sm">{{ $isAdmin ? __('start.home.closed_admin') : __('start.home.closed_resident') }}</p>
                @if ($isAdmin)
                    <a href="{{ $wizardUrl }}" class="btn btn-primary mt-2" data-testid="house-closed-continue">
                        <x-lucide-list-checks class="size-5" aria-hidden="true" /> {{ __('start.house.continue_opening') }}
                    </a>
                @endif
            </x-ui.banner>
        @elseif ($outages)
            <livewire:outage.banner :calm-line="true" />
        @else
            <div class="banner" role="status">
                <x-lucide-circle-check class="mt-0.5 size-5 shrink-0 text-(--color-success)" aria-hidden="true" />
                <p>{{ __('home.status_calm') }}</p>
            </div>
        @endif
    </section>

    {{-- An administrator who holds a role here but no apartment yet (FIRSTRUN-001). --}}
    @if ($canLinkSelf)
        <section class="card stack" aria-labelledby="noapt-h" data-testid="home-no-apartment">
            <h2 id="noapt-h" class="text-lg">{{ __('start.home.no_apartment_title') }}</h2>
            <p class="text-sm">{{ __('start.home.no_apartment_body') }}</p>
            <a href="{{ route('building.me', $building) }}#link-myself" class="btn btn-block">
                <x-lucide-house class="size-5" aria-hidden="true" /> {{ __('start.link.button') }}
            </a>
        </section>
    @elseif ($membership === null)
        {{-- An elder / officer / volunteer without an apartment here: how to join with the letter's code. --}}
        <section class="card stack" aria-labelledby="noapt-h" data-testid="home-no-apartment-join">
            <h2 id="noapt-h" class="text-lg">{{ __('start.home.no_apartment_title') }}</h2>
            <p class="text-sm">{{ __('start.no_home.join_body') }}</p>
            <a href="{{ route('join') }}" class="btn btn-block">
                <x-lucide-mail-plus class="size-5" aria-hidden="true" /> {{ __('start.no_home.join_button') }}
            </a>
        </section>
    @endif

    {{-- Every module has a card (requirement: no blank home): open = the big action, closed = why + who opens it. --}}
    <section aria-label="{{ __('home.actions') }}" class="grid grid-cols-2 gap-3" data-testid="module-tiles">
        @foreach ($tiles as $tile)
            @if ($tile['reason'] === null)
                @if ($tile['key'] === 'heating')
                    <x-ui.big-action icon="thermometer" :label="__('home.action.heating')" :href="route('building.heating', $building).($membership?->isApproved() ? '?report=1' : '')"
                                     :hint="$membership?->isPending() ? __('home.action.read_only') : null" />
                @elseif ($tile['key'] === 'outages')
                    <x-ui.big-action icon="droplet-off" :label="__('home.action.water')" :href="route('building.water', $building).($membership?->isApproved() ? '?report=1' : '')"
                                     :hint="$membership?->isPending() ? __('home.action.read_only') : null" />
                @else
                    <x-ui.big-action icon="car" :label="__('home.action.check_car')" :href="route('building.parking', $building)"
                                     :hint="$membership?->isPending() ? __('home.action.read_only') : null" />
                    <x-ui.big-action icon="badge-check" :label="__('home.action.permits')" :href="route('building.parking.mine', $building)" />
                @endif
            @else
                <div class="card grid content-start gap-2" data-testid="tile-locked-{{ $tile['key'] }}" data-reason="{{ $tile['reason'] }}">
                    {{-- Icon above the title: next to it, "Stāvvieta" broke mid-word at 320 px. --}}
                    <x-lucide-lock class="size-6 text-(--color-fg-muted)" aria-hidden="true" />
                    <p class="font-semibold [overflow-wrap:normal]">{{ __('start.module.'.$tile['key']) }}</p>
                    <p class="text-sm muted">{{ __('start.locked.'.$tile['reason'].'.short') }}</p>
                    @if (isset($fixUrls[$tile['key']]) && $tile['reason'] !== 'house_closed')
                        <a href="{{ $fixUrls[$tile['key']] }}" class="text-sm font-semibold">{{ __('start.locked.'.$tile['reason'].'.fix') }}</a>
                    @endif
                </div>
            @endif
        @endforeach
    </section>

    @if ($heating && ! $largeText)
        <livewire:heating.round-card />
    @endif

    {{-- Kept in large-text mode too (one row; elderly elders use large text — review fix). --}}
    @if ($isElder || $isAdmin)
        <section class="card stack" aria-labelledby="tools-h">
            <h2 id="tools-h" class="text-lg">{{ __('home.tools.title') }}</h2>
            <a href="{{ route('building.elder', $building) }}" class="list-row no-underline text-(--color-fg)">
                <span class="flex items-center gap-3">
                    <x-lucide-hand-helping class="size-6 text-(--color-accent)" aria-hidden="true" />
                    <span class="font-semibold">{{ __('home.tools.helper') }}</span>
                </span>
                <span class="flex items-center gap-2">
                    @if ($pendingForMe > 0)
                        <span class="chip chip-warning tnum">{{ trans_choice('home.tools.pending', $pendingForMe, ['count' => $pendingForMe]) }}</span>
                    @endif
                    <x-lucide-chevron-right class="size-5" aria-hidden="true" />
                </span>
            </a>
            @if ($isAdmin)
                <a href="{{ url('/admin/b/'.$building->slug) }}" class="list-row no-underline text-(--color-fg)">
                    <span class="flex items-center gap-3">
                        <x-lucide-building-2 class="size-6 text-(--color-accent)" aria-hidden="true" />
                        <span class="font-semibold">{{ __('home.tools.admin') }}</span>
                    </span>
                    <x-lucide-chevron-right class="size-5" aria-hidden="true" />
                </a>
            @endif
        </section>
    @endif

    @if ($section && ! $largeText)
        <section class="card stack" aria-labelledby="map-h">
            <div class="flex items-center justify-between gap-2">
                <h2 id="map-h" class="text-lg">{{ __('home.my_stairwell', ['n' => $section['number']]) }}</h2>
                <span class="chip tnum shrink-0 whitespace-nowrap">{{ $section['first'] }}–{{ $section['last'] }}</span>
            </div>
            @if ($heatingView)
                {{-- Name from the sr-only text, the mini grid hidden from AT (an aria-label that does not contain the visible
                     grid text failed WCAG 2.5.3 label-in-name in Lighthouse). --}}
                <a href="{{ route('building.heating', $building) }}" class="block no-underline text-(--color-fg)" wire:navigate>
                    <span class="sr-only">{{ __('home.map_open') }}</span>
                    <div aria-hidden="true"><x-ui.building-grid :section="$section" :highlight="$apartment?->number" :compact="true" :interactive="false" :columns="$heatingView->columns" /></div>
                </a>
                <p class="text-sm muted">{{ __('home.map_heating_hint') }}</p>
            @else
                <x-ui.building-grid :section="$section" :highlight="$apartment?->number" />
                <p class="text-sm muted">{{ __('home.map_hint') }}</p>
            @endif
        </section>
    @endif

    @if (! $largeText)
        <section class="card stack" aria-labelledby="house-h">
            <h2 id="house-h" class="text-lg">{{ __('home.my_house.title') }}</h2>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="muted">{{ __('home.my_house.address') }}</dt>
                <dd class="font-semibold">{{ $building->address_line }}, {{ $building->city }}</dd>
                @if ($building->series)
                    <dt class="muted">{{ __('home.my_house.series') }}</dt>
                    <dd class="font-semibold">{{ $building->series->code }}</dd>
                @endif
                @if ($building->manager_name)
                    <dt class="muted">{{ __('home.my_house.manager') }}</dt>
                    <dd class="font-semibold">{{ $building->manager_name }}</dd>
                @endif
                @if ($apartment)
                    <dt class="muted">{{ __('home.my_house.apartment') }}</dt>
                    <dd class="font-semibold tnum">{{ __('onboarding.role.apartment', ['n' => $apartment->number]) }} · {{ __('onboarding.role.floor', ['n' => $apartment->floor]) }}</dd>
                @endif
            </dl>
            <p class="text-sm muted">{{ __('home.my_house.requests') }}</p>
        </section>

        <section class="card flex flex-wrap items-center justify-between gap-3" aria-labelledby="deadline-h">
            <div>
                <h2 id="deadline-h" class="text-base font-bold">{{ __('home.deadline.water_title') }}</h2>
                <p class="text-sm muted">{{ __('home.deadline.water_body') }}</p>
            </div>
            <span class="chip chip-warning shrink-0 tnum">{{ trans_choice('home.deadline.days', $daysToDeadline, ['count' => $daysToDeadline]) }}</span>
        </section>
    @endif
</div>

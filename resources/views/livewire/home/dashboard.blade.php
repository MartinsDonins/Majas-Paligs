<div class="stack-lg">
    @if (session('status') === 'joined' || $membership?->isPending())
        <x-onboarding.pending-note />
    @endif

    <section aria-labelledby="home-h" class="stack">
        <h1 id="home-h" class="sr-only">{{ __('nav.home') }}</h1>
        @if ($outages)
            <livewire:outage.banner :calm-line="true" />
        @else
            <div class="banner" role="status">
                <x-lucide-circle-check class="mt-0.5 size-5 shrink-0 text-(--color-success)" aria-hidden="true" />
                <p>{{ __('home.status_calm') }}</p>
            </div>
        @endif
    </section>

    @if ($heating || $outages || $parking)
        <section aria-label="{{ __('home.actions') }}" class="grid grid-cols-2 gap-3">
            @if ($heating)
                <x-ui.big-action icon="thermometer" :label="__('home.action.heating')" :href="route('building.heating', $building).($membership?->isApproved() ? '?report=1' : '')"
                                 :hint="$membership?->isPending() ? __('home.action.read_only') : null" />
            @endif
            @if ($outages)
                <x-ui.big-action icon="droplet-off" :label="__('home.action.water')" :href="route('building.water', $building).($membership?->isApproved() ? '?report=1' : '')"
                                 :hint="$membership?->isPending() ? __('home.action.read_only') : null" />
            @endif
            @if ($parking)
                <x-ui.big-action icon="car" :label="__('home.action.check_car')" />
                <x-ui.big-action icon="badge-check" :label="__('home.action.permits')" />
            @endif
        </section>
    @else
        <x-ui.banner variant="info" icon="hammer">
            <p class="font-semibold">{{ __('home.modules_soon_title') }}</p>
            <p class="text-sm">{{ __('home.modules_soon_body') }}</p>
        </x-ui.banner>
    @endif

    @if ($heating && ! $largeText)
        <livewire:heating.round-card />
    @endif

    @if (($isElder || $isAdmin) && ! $largeText)
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
                <a href="{{ url('/manage/'.$building->slug) }}" class="list-row no-underline text-(--color-fg)">
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
                <span class="chip tnum">{{ $section['first'] }}–{{ $section['last'] }}</span>
            </div>
            @if ($heatingView)
                <a href="{{ route('building.heating', $building) }}" class="block no-underline text-(--color-fg)" aria-label="{{ __('home.map_open') }}" wire:navigate>
                    <x-ui.building-grid :section="$section" :highlight="$apartment?->number" :compact="true" :interactive="false" :columns="$heatingView->columns" />
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

        <section class="card flex items-center justify-between gap-3" aria-labelledby="deadline-h">
            <div>
                <h2 id="deadline-h" class="text-base font-bold">{{ __('home.deadline.water_title') }}</h2>
                <p class="text-sm muted">{{ __('home.deadline.water_body') }}</p>
            </div>
            <span class="chip chip-warning shrink-0 tnum">{{ trans_choice('home.deadline.days', $daysToDeadline, ['count' => $daysToDeadline]) }}</span>
        </section>
    @endif
</div>

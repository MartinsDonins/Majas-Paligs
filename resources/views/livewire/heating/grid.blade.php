<div class="stack-lg" wire:poll.visible.{{ $pollSeconds }}s>
    <section class="stack">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="page-title">{{ __($mode === 'outage' ? 'outage.title' : 'heating.title') }}</h1>
            <div class="flex flex-wrap items-center gap-2">
                @if ($staff)
                    <span class="chip chip-accent text-xs"><x-lucide-shield-check class="size-4" aria-hidden="true" /> {{ __('heating.staff.badge') }}</span>
                @endif
                @if ($mode === 'heating')
                    <span class="chip tnum">
                        <x-lucide-calendar-check class="size-4" aria-hidden="true" />
                        @if ($round === 'rolling')
                            {{ __('heating.round.rolling', ['days' => $building->settings->adhocReportValidDays]) }}
                        @else
                            {{ __('heating.round.chip', ['title' => optional($rounds->first(fn ($r) => $r->roundKey() === $round))->title ?? '']) }}
                        @endif
                    </span>
                @endif
            </div>
        </div>

        @if ($heatingOn && $outagesOn)
            <nav class="segmented" aria-label="{{ __('heating.mode.label') }}">
                <a href="{{ route('building.heating', $building) }}?k={{ $section->number }}" wire:navigate @if ($mode === 'heating') aria-current="page" @endif>
                    <x-lucide-thermometer class="size-5" aria-hidden="true" /> {{ __('heating.mode.heating') }}
                </a>
                <a href="{{ route('building.water', $building) }}?k={{ $section->number }}" wire:navigate @if ($mode === 'outage') aria-current="page" @endif>
                    <x-lucide-droplet class="size-5" aria-hidden="true" /> {{ __('heating.mode.water') }}
                </a>
            </nav>
        @endif
    </section>

    @if ($outagesOn)
        <livewire:outage.banner />
    @endif

    @if ($flash)
        <x-ui.banner :variant="$flash === 'report_limit' ? 'warning' : 'info'" icon="circle-check">{{ __('heating.flash.'.$flash) }}</x-ui.banner>
    @endif

    @if ($pending)
        <x-ui.banner variant="info" icon="clock">{{ __('heating.pending_read_only') }}</x-ui.banner>
    @endif

    {{-- Stairwells: tabs + whole-building mini map --}}
    <section class="stack" aria-labelledby="stairwells-h">
        <h2 id="stairwells-h" class="sr-only">{{ __('heating.stairwells') }}</h2>
        <div class="tabs-scroll" role="group" aria-label="{{ __('heating.stairwells') }}">
            @foreach ($sections as $s)
                <button type="button" wire:click="selectStairwell({{ $s->number }})" class="choice min-h-12 justify-center px-3 text-sm tnum"
                        aria-pressed="{{ $s->number === $section->number ? 'true' : 'false' }}">
                    {{ __('heating.stairwell_tab', ['n' => $s->number]) }}
                </button>
            @endforeach
        </div>
        @if ($mode === 'heating' && $sections->count() > 1)
            <x-heating.mini-map :sections="$sections" :columns="$miniMap" :active="$section->number" />
        @endif
    </section>

    @if ($mode === 'heating')
        <div class="flex flex-wrap items-center gap-2">
            @if ($rounds->isNotEmpty())
                <div class="segmented flex-1" role="group" aria-label="{{ __('heating.round.label') }}">
                    <button type="button" wire:click="setRound('rolling')" aria-pressed="{{ $round === 'rolling' ? 'true' : 'false' }}">{{ __('heating.round.rolling_short') }}</button>
                    @foreach ($rounds as $r)
                        <button type="button" wire:click="setRound('{{ $r->roundKey() }}')" aria-pressed="{{ $round === $r->roundKey() ? 'true' : 'false' }}">{{ $r->title }}</button>
                    @endforeach
                </div>
            @endif
            <div class="segmented" role="group" aria-label="{{ __('heating.display.label') }}">
                <button type="button" wire:click="setDisplay('grid')" aria-pressed="{{ $display === 'grid' ? 'true' : 'false' }}">
                    <x-lucide-layout-grid class="size-5" aria-hidden="true" /> {{ __('heating.display.grid') }}
                </button>
                <button type="button" wire:click="setDisplay('list')" aria-pressed="{{ $display === 'list' ? 'true' : 'false' }}">
                    <x-lucide-list class="size-5" aria-hidden="true" /> {{ __('heating.display.list') }}
                </button>
            </div>
        </div>
    @endif

    @if ($mode === 'heating' && $display === 'list')
        <x-heating.riser-list :heating="$heating" :my-riser-ids="$myRiserIds" />
    @else
        <section class="card stack" aria-labelledby="grid-h">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="grid-h" class="text-lg">{{ __('heating.grid.title', ['n' => $section->number]) }}</h2>
                <span class="chip tnum shrink-0 whitespace-nowrap">{{ $structure['first'] }}–{{ $structure['last'] }}</span>
            </div>
            <x-heating.grid :structure="$structure" :heating="$heating" :mode="$mode" :mine="$mine" :overlay="$overlay"
                            :my-riser-ids="$myRiserIds" :can-dispute="$canReport" />
            @if ($mode === 'heating')
                <p class="text-sm muted">{{ __($staff ? 'heating.grid.hint_staff' : 'heating.grid.hint') }}</p>
                @if ($heating->worst() === null && collect($heating->columns)->every(fn ($c) => $c->distinctApartments === 0))
                    <x-ui.empty-state :title="__('heating.empty.section')" />
                @endif
                @if (collect($heating->risers)->contains(fn ($r) => $r->isUnder()) && ! $staff)
                    <p class="text-sm flex gap-2"><x-lucide-wind class="mt-0.5 size-4 shrink-0 text-(--color-accent)" aria-hidden="true" /> {{ __('heating.tip.air') }}</p>
                @endif
            @else
                <p class="text-sm muted">{{ __('outage.grid.hint') }}</p>
            @endif
        </section>
    @endif

    <x-heating.legend :mode="$mode" />

    @if ($mode === 'heating')
        @if ($canReport)
            <x-ui.bottom-sheet id="report" :title="__('heating.report.title', ['n' => $mine->number])" :open="$openReport">
                <x-slot:trigger>
                    <div class="sticky-action">
                        <button type="button" x-on:click="show" class="btn btn-primary btn-block shadow-(--shadow-2)">
                            <x-lucide-thermometer class="size-6" aria-hidden="true" /> {{ __('heating.report.cta') }}
                        </button>
                    </div>
                </x-slot:trigger>
                <livewire:heating.report-sheet />
            </x-ui.bottom-sheet>
        @endif
    @elseif ($mine)
        <x-ui.bottom-sheet id="outage" :title="__('outage.report.title')" :open="$openReport">
            <x-slot:trigger>
                <div class="sticky-action">
                    <button type="button" x-on:click="show" class="btn btn-primary btn-block shadow-(--shadow-2)" @if ($pending) disabled @endif>
                        <x-lucide-droplet-off class="size-6" aria-hidden="true" /> {{ __('outage.report.cta') }}
                    </button>
                </div>
            </x-slot:trigger>
            <livewire:outage.report-sheet />
        </x-ui.bottom-sheet>
    @endif

    @if ($canGenerate)
        <section class="card stack" aria-labelledby="mgr-h">
            <h2 id="mgr-h" class="text-lg">{{ __('heating.manager.title') }}</h2>
            <p class="text-sm muted">{{ __($mode === 'outage' ? 'heating.manager.body_outage' : 'heating.manager.body') }}</p>
            <button type="button" wire:click="managerReport" class="btn btn-block" data-requires-online>
                <x-lucide-file-down class="size-5" aria-hidden="true" /> {{ __('heating.manager.download') }}
            </button>
            <p class="text-xs muted flex items-center gap-2"><x-lucide-history class="size-4" aria-hidden="true" /> {{ __('heating.manager.logged') }}</p>
        </section>
    @endif

    <x-ui.privacy-note>{{ __($staff ? 'heating.privacy.staff' : 'heating.privacy.resident') }}</x-ui.privacy-note>
</div>

<div class="stack-lg">
    <section class="stack">
        <h1 class="page-title">{{ __('parking.progress.title') }}</h1>
        <x-parking.tabs :building="$building" active="progress" />
        <p class="lead">{{ __('parking.progress.lead') }}</p>
    </section>

    @if ($amnestyUntil)
        <x-ui.banner variant="info" icon="party-popper">
            <p class="font-semibold">{{ __('parking.declare.amnesty_title', ['date' => $amnestyUntil->format('d.m.Y')]) }}</p>
            <p class="text-sm">{{ __('parking.declare.amnesty_body') }}</p>
        </x-ui.banner>
    @endif

    <section class="card flex flex-col items-center gap-3 text-center" aria-labelledby="total-h">
        <h2 id="total-h" class="text-lg">{{ __('parking.progress.house') }}</h2>
        <x-ui.progress-ring :percent="$total['percent']" :label="__('parking.progress.house')"
                           :caption="__('parking.progress.caption', ['covered' => $total['covered'], 'total' => $total['total']])" />
    </section>

    <section class="card stack" aria-labelledby="sections-h">
        <h2 id="sections-h" class="text-lg">{{ __('parking.progress.stairwells') }}</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
            @foreach ($sections as $s)
                <x-ui.progress-ring :percent="$s['percent']" :mine="$mySection === $s['section']"
                                   :label="__('parking.progress.stairwell', ['n' => $s['section']]).($mySection === $s['section'] ? ' · '.__('grid.you') : '')"
                                   :caption="__('parking.progress.caption', ['covered' => $s['covered'], 'total' => $s['total']])" />
            @endforeach
        </div>
        <p class="text-sm muted">{{ __('parking.progress.aggregate_only') }}</p>
    </section>

    @if ($canDeclare)
        <a href="{{ route('building.parking.declare', $building) }}" class="btn btn-primary btn-block" wire:navigate>
            <x-lucide-clipboard-check class="size-5" aria-hidden="true" /> {{ __('parking.progress.cta') }}
        </a>
    @endif
</div>

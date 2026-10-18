@props(['riser', 'staffView' => null, 'canDispute' => false, 'mine' => false])
{{--
    One riser's summary. Resident data = ResidentRiserView (counts only: status, confirmed, apartments, floors covered
    as a NUMBER, status counts). Staff (admin; elder of this stairwell) additionally get the per-floor bar and the
    "only upper floors" hint from StaffRiserView.
--}}
@php
    /** @var \App\Services\Heating\Views\ResidentRiserView $riser */
    $state = $riser->displayState();
    $tone = $riser->distinctApartments > 0 ? $riser->status->value : null;
    $c = $riser->statusCounts;
@endphp
<article class="rounded-(--radius-md) border border-(--color-line) p-3 space-y-2" wire:key="riser-{{ $riser->riserId }}">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h3 class="text-base font-bold">{{ \App\Services\Heating\RiserLabel::for($riser->code) }}</h3>
            <p class="text-xs muted tnum">{{ __('heating.riser.code', ['code' => $riser->code]) }}@if ($mine) · <strong class="text-(--color-accent)">{{ __('heating.riser.yours') }}</strong>@endif</p>
        </div>
        <x-heating.state-chip :state="$state" :tone="$tone" size="sm" />
    </div>

    @if ($riser->distinctApartments > 0)
        <p class="text-sm">
            {{ trans_choice('heating.riser.apartments', $riser->distinctApartments, ['count' => $riser->distinctApartments, 'total' => $riser->coverageTotal]) }}
        </p>
        <p class="text-sm muted tnum">{{ __('heating.riser.counts', ['cold' => $c['cold'], 'lukewarm' => $c['lukewarm'], 'warm' => $c['warm'], 'overheated' => $c['overheated']]) }}</p>
    @else
        <p class="text-sm muted">{{ __('heating.empty.riser') }}</p>
    @endif

    <x-heating.coverage :covered="$riser->floorsCovered" :total="$riser->floorsTotal" />

    @if ($staffView)
        <div class="space-y-2">
            <p class="text-xs font-semibold muted">{{ __('heating.staff.floors') }}</p>
            <div class="floorbar" role="img" aria-label="{{ __('heating.staff.floors_label', ['floors' => implode(', ', $staffView->floorsReported) ?: '—']) }}">
                @for ($f = 1; $f <= $riser->floorsTotal; $f++)
                    <span @if (in_array($f, $staffView->floorsReported, true)) data-on @endif title="{{ $f }}"></span>
                @endfor
            </div>
            @if ($staffView->topFloorOnly)
                <x-ui.banner variant="info" icon="wind">{{ __('heating.staff.top_floor_only') }}</x-ui.banner>
            @endif
            @if ($staffView->wholeRiserUnder)
                <x-ui.banner variant="warning" icon="snowflake">{{ __('heating.staff.whole_riser_under') }}</x-ui.banner>
            @endif
        </div>
    @elseif ($riser->isUnder())
        <p class="text-sm muted flex gap-2"><x-lucide-wind class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ __('heating.tip.air') }}</p>
    @endif

    @if ($riser->checkedState)
        <p class="text-sm flex items-center gap-2"><x-lucide-circle-check class="size-4 text-(--color-success)" aria-hidden="true" /> {{ __('heating.riser.checked', ['result' => __('enum.riser_check_result.'.$riser->checkedState)]) }}</p>
    @endif

    @if ($riser->disputed)
        <p class="text-sm flex items-center gap-2"><x-lucide-help-circle class="size-4 text-(--color-warning)" aria-hidden="true" /> {{ __('heating.riser.disputed') }}</p>
    @elseif ($canDispute)
        <button type="button" wire:click="dispute({{ $riser->riserId }})" wire:confirm="{{ __('heating.dispute.confirm') }}" class="btn-link text-sm" data-requires-online>
            {{ __('heating.dispute.button') }}
        </button>
    @endif
</article>

@props(['heating', 'myRiserIds' => []])
{{-- Accessible list mode ("Stāvadu kopsavilkums", worst first) — the equivalent of the grid (DESIGN-SYSTEM §5.1). --}}
@php /** @var \App\Services\Heating\Views\HeatingSectionView $heating */ @endphp
<section class="card stack" aria-labelledby="riser-list-h">
    <h2 id="riser-list-h" class="text-lg">{{ __('heating.list.title') }}</h2>
    @if ($heating->risers === [])
        <p class="muted">{{ __('heating.empty.section') }}</p>
    @else
        <ul class="space-y-2">
            @foreach ($heating->risers as $riser)
                @php $state = $riser->displayState(); $c = $riser->statusCounts; @endphp
                <li class="rounded-(--radius-md) border border-(--color-line) p-3" wire:key="rl-{{ $riser->riserId }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-semibold">
                            {{ \App\Services\Heating\RiserLabel::for($riser->code) }}
                            @if (in_array($riser->riserId, $myRiserIds, true))<span class="chip chip-accent text-xs">{{ __('heating.riser.yours') }}</span>@endif
                        </p>
                        <x-heating.state-chip :state="$state" :tone="$riser->distinctApartments > 0 ? $riser->status->value : null" size="sm" />
                    </div>
                    <p class="mt-1 text-sm muted tnum">
                        {{ __('heating.list.line', ['cold' => $c['cold'], 'lukewarm' => $c['lukewarm'], 'warm' => $c['warm'], 'overheated' => $c['overheated'], 'none' => $riser->noDataFloors()]) }}
                    </p>
                    <x-heating.coverage class="mt-2" :covered="$riser->floorsCovered" :total="$riser->floorsTotal" />
                </li>
            @endforeach
        </ul>
    @endif
</section>

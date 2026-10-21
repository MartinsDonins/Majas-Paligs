{{-- Roster below gate L-R (GOV-012, AC-R2-10): why it is closed and what is missing — never any data. --}}
<x-filament::section :heading="__('roster.gate.closed_title')" icon="heroicon-o-lock-closed" data-testid="roster-closed">
    <p class="text-sm">{{ __('roster.gate.closed_lead') }}</p>
    @if ($missing !== [])
        <p class="mt-3 text-sm font-semibold">{{ __('roster.gate.missing_title') }}</p>
        <ul class="mt-1 list-disc ps-5 text-sm">
            @foreach ($missing as $label)
                <li>{{ $label }}</li>
            @endforeach
        </ul>
    @endif
    <div class="mt-4">
        <x-filament::button tag="a" :href="$wizardUrl" icon="heroicon-o-arrow-right">{{ __('roster.gate.continue') }}</x-filament::button>
    </div>
</x-filament::section>

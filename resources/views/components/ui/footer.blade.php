@props(['building' => null, 'compact' => false])
{{-- Footer: version (global rule), privacy notice, "not an emergency channel" + manager emergency phone. --}}
<footer class="mx-auto mt-10 w-full max-w-[40rem] px-4 pb-6 text-sm text-(--color-fg-muted)" role="contentinfo">
    @if ($building && ! $compact)
        <div class="banner mb-4 text-(--color-fg)">
            <x-lucide-siren class="size-5 shrink-0 text-(--color-danger)" aria-hidden="true" />
            <div>
                <p class="font-semibold">{{ __('shell.not_emergency') }}</p>
                @if ($building->manager_emergency_phone)
                    <p>{{ __('shell.emergency_phone') }}:
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $building->manager_emergency_phone) }}" class="font-bold tnum">{{ $building->manager_emergency_phone }}</a>
                        @if ($building->manager_name) <span>({{ $building->manager_name }})</span> @endif
                    </p>
                @else
                    <p>{{ __('shell.emergency_phone_missing') }}</p>
                @endif
            </div>
        </div>
    @endif
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <a href="{{ $building ? route('building.privacy', $building) : route('privacy') }}">{{ __('common.privacy') }}</a>
        <a href="{{ route('terms') }}">{{ __('common.terms') }}</a>
        <span class="tnum" data-testid="app-version">{{ __('common.version') }} {{ config('app.version') }}</span>
    </div>
    <p class="mt-2 text-xs">{{ __('shell.not_bis_rnp') }}</p>
</footer>

@props(['seconds'])
{{-- Calm rate-limit state with a live countdown (DESIGN-SYSTEM §3 "Pagaidiet … un mēģiniet vēlreiz"). --}}
<div x-data="retryCountdown" data-seconds="{{ (int) $seconds }}" class="banner banner-warning" role="status">
    <x-lucide-hourglass class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
    <div>
        <p class="font-semibold">{{ __('parking.retry.title') }}</p>
        <p x-show="waiting">{{ __('parking.retry.body') }} <span class="tnum font-bold" x-text="label"></span></p>
        <p x-show="done" x-cloak>{{ __('parking.retry.ready') }}</p>
    </div>
</div>

<div class="banner banner-info" role="note">
    <x-lucide-scale class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
    <div class="min-w-0 flex-1">
        <p class="font-semibold">{{ __('parking.legal.title') }}@if ($since) <span class="font-normal tnum">· {{ __('parking.legal.since', ['date' => $since->format('d.m.Y')]) }}</span>@endif</p>
        <p class="text-sm">{{ $text }}</p>
    </div>
</div>

{{-- "Gaida apstiprinājumu — parasti 1–2 dienas" + "Pievienot ikonu ekrānam" guide (DESIGN-SYSTEM §6.1 step 4). --}}
<section class="card stack">
    <div class="flex items-start gap-3">
        <x-lucide-clock class="size-6 shrink-0 text-(--color-warning)" aria-hidden="true" />
        <div>
            <h2 class="text-lg">{{ __('onboarding.pending.title') }}</h2>
            <p class="muted">{{ __('onboarding.pending.body') }}</p>
        </div>
    </div>
    <div x-data="disclosure" class="border-t border-(--color-line) pt-3">
        <button type="button" class="btn-link" x-on:click="toggle" x-bind:aria-expanded="expanded">
            <x-lucide-smartphone class="inline size-4" aria-hidden="true" /> {{ __('onboarding.a2hs.title') }}
        </button>
        <div x-show="expanded" x-cloak class="stack mt-2 text-sm">
            <p><strong>Android:</strong> {{ __('onboarding.a2hs.android') }}</p>
            <p><strong>iPhone:</strong> {{ __('onboarding.a2hs.ios') }}</p>
        </div>
    </div>
</section>

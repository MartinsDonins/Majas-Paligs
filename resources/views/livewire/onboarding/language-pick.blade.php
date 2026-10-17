<div class="stack-lg">
    <section class="pt-6 text-center">
        <x-brand-mark class="mx-auto size-16" />
        <h1 class="page-title mt-4">{{ __('landing.title') }}</h1>
        <p class="lead mx-auto max-w-md">{{ __('landing.subtitle') }}</p>
    </section>

    <section class="card stack" aria-labelledby="lang-h">
        <h2 id="lang-h" class="text-lg">{{ __('landing.choose_language') }} · Выберите язык</h2>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach (['lv' => 'Latviešu', 'ru' => 'Русский'] as $code => $name)
                <form method="POST" action="{{ route('locale.set', $code) }}">
                    @csrf
                    <button type="submit" lang="{{ $code }}" class="choice min-h-16 justify-center text-lg" aria-pressed="{{ app()->getLocale() === $code ? 'true' : 'false' }}">
                        @if (app()->getLocale() === $code)
                            <x-lucide-check class="size-5" aria-hidden="true" />
                        @endif
                        {{ $name }}
                    </button>
                </form>
            @endforeach
        </div>
        <button type="button" wire:click="toggleLargeText" class="choice" aria-pressed="{{ $largeText ? 'true' : 'false' }}">
            <x-lucide-type class="size-6 shrink-0 text-(--color-accent)" aria-hidden="true" />
            <span class="flex-1">{{ __('landing.large_text') }}</span>
            <span class="chip">{{ $largeText ? __('common.on') : __('common.off') }}</span>
        </button>
    </section>

    <section class="stack">
        @auth
            <a href="{{ route('home.redirect') }}" class="btn btn-primary btn-block">{{ __('landing.continue_home') }}</a>
            <a href="{{ route('join') }}" class="btn btn-block">{{ __('landing.have_code') }}</a>
        @else
            <a href="{{ route('join') }}" class="btn btn-primary btn-block">
                <x-lucide-mail class="size-5" aria-hidden="true" />
                {{ __('landing.have_code') }}
            </a>
            <a href="{{ route('login') }}" class="btn btn-block">{{ __('landing.login_email') }}</a>
            <a href="{{ route('login.pin') }}" class="btn btn-block">{{ __('landing.login_pin') }}</a>
        @endauth
    </section>

    <x-ui.banner variant="info" icon="shield-check">
        <p class="font-semibold">{{ __('landing.trust_title') }}</p>
        <p class="text-sm">{{ __('landing.trust_body') }}</p>
    </x-ui.banner>
</div>

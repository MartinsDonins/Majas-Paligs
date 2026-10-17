{{-- GET /login/confirm — never changes state (mail scanners cannot burn the link). The token arrives in the URL
     fragment (#t=…); Alpine copies it into the hidden field and clears the address bar; the user taps "Ieiet". --}}
<x-layouts::guest :title="__('auth.confirm.title')">
    <div class="stack-lg">
        <section class="stack text-center">
            <x-lucide-log-in class="mx-auto size-12 text-(--color-accent)" aria-hidden="true" />
            <h1 class="page-title">{{ __('auth.confirm.title') }}</h1>
            <p class="lead">{{ __('auth.confirm.lead') }}</p>
        </section>

        <form method="POST" action="{{ route('login.confirm.submit') }}" class="card stack"
              x-data="fragmentSecret" data-param="t" data-target="t">
            @csrf
            <input type="hidden" name="t" value="">
            <label class="choice font-normal">
                <input type="checkbox" name="remember" value="1" checked class="size-5 accent-(--color-accent)">
                <span>{{ __('auth.remember') }}</span>
            </label>
            <button type="submit" class="btn btn-primary btn-block">{{ __('auth.login') }}</button>
        </form>

        <p class="text-sm muted text-center">{{ __('auth.confirm.not_you') }}</p>
    </div>
</x-layouts::guest>

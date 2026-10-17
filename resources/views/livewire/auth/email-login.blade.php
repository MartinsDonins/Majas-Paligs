<div class="stack-lg">
    @if (session('status') === 'link_invalid')
        <x-ui.banner variant="warning" icon="circle-alert">{{ __('auth.email.link_invalid') }}</x-ui.banner>
    @endif

    @if (! $sent)
        <section class="stack">
            <h1 class="page-title">{{ __('auth.login') }}</h1>
            <p class="lead">{{ __('auth.email.lead') }}</p>
        </section>

        <form wire:submit="send" class="card stack" novalidate>
            <x-ui.field id="email" :label="__('auth.email.label')" :error="$errors->first('email')">
                <input id="email" type="email" wire:model="email" class="input" autocomplete="email" inputmode="email" maxlength="254"
                       @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            </x-ui.field>
            <label class="choice font-normal">
                <input type="checkbox" wire:model="remember" class="size-5 accent-(--color-accent)">
                <span>{{ __('auth.remember') }}</span>
            </label>
            <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">{{ __('auth.email.send') }}</button>
        </form>
    @else
        <section class="card stack" role="status">
            <x-lucide-mail class="size-10 text-(--color-accent)" aria-hidden="true" />
            <h1 class="text-xl">{{ __('auth.email.sent_title') }}</h1>
            <p>{{ __('auth.email.sent_body_generic') }}</p>
        </section>

        <form method="POST" action="{{ route('login.code') }}" class="card stack" novalidate>
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">
            <input type="hidden" name="remember" value="{{ $remember ? '1' : '0' }}">
            <x-ui.field id="code" :label="__('auth.email.code_label')" :help="__('auth.email.code_help')" :error="$errors->first('code')">
                <input id="code" name="code" class="input input-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7"
                       pattern="[0-9 ]*" placeholder="000 000" aria-describedby="code-help">
            </x-ui.field>
            <button type="submit" class="btn btn-primary btn-block">{{ __('auth.login') }}</button>
        </form>
        <button type="button" wire:click="again" class="btn-link">{{ __('auth.email.other_address') }}</button>
    @endif

    <div class="card stack">
        <p class="font-semibold">{{ __('auth.no_email_title') }}</p>
        <a href="{{ route('login.pin') }}" class="btn btn-block">
            <x-lucide-key-round class="size-5" aria-hidden="true" /> {{ __('landing.login_pin') }}
        </a>
        <a href="{{ route('join') }}" class="btn-link">{{ __('landing.have_code') }}</a>
    </div>
</div>

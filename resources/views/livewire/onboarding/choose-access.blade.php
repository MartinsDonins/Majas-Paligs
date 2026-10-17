<div class="stack-lg">
    <x-ui.progress-dots :step="4" />

    @if ($createdHandle)
        {{-- PIN account created and logged in: show the handle once, then the pending state. --}}
        <section class="card stack text-center" role="status">
            <x-lucide-circle-check class="mx-auto size-12 text-(--color-success)" aria-hidden="true" />
            <h1 class="text-xl">{{ __('onboarding.access.pin_created') }}</h1>
            <p>{{ __('onboarding.access.handle_intro') }}</p>
            <p class="mono-code tnum" aria-label="{{ __('onboarding.access.handle_label') }}">{{ $createdHandle }}</p>
            <p class="text-sm muted">{{ __('onboarding.access.handle_card') }}</p>
        </section>
        <x-onboarding.pending-note />
        @if ($slug)
            <a href="{{ route('building.home', $slug) }}" class="btn btn-primary btn-block">{{ __('common.continue') }}</a>
        @endif
    @elseif ($mailSent)
        <section class="card stack" role="status">
            <x-lucide-mail class="size-10 text-(--color-accent)" aria-hidden="true" />
            <h1 class="text-xl">{{ __('auth.email.sent_title') }}</h1>
            <p>{{ __('auth.email.sent_body') }}</p>
            <p class="text-sm muted">{{ __('auth.email.same_device') }}</p>
        </section>
        <form method="POST" action="{{ route('login.code') }}" class="card stack">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">
            <input type="hidden" name="remember" value="{{ $remember ? '1' : '0' }}">
            <x-ui.field id="code" :label="__('auth.email.code_label')" :help="__('auth.email.code_help')">
                <input id="code" name="code" class="input input-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*" placeholder="000 000">
            </x-ui.field>
            <button type="submit" class="btn btn-primary btn-block">{{ __('auth.login') }}</button>
        </form>
    @else
        <section class="stack">
            <h1 class="page-title">{{ __('onboarding.access.title') }}</h1>
            <p class="lead">{{ __('onboarding.access.lead') }}</p>
        </section>

        <div class="grid gap-3">
            <button type="button" wire:click="pick('email')" class="choice" aria-pressed="{{ $method === 'email' ? 'true' : 'false' }}">
                <x-lucide-mail class="size-6 shrink-0 text-(--color-accent)" aria-hidden="true" />
                <span class="flex-1">
                    <span class="block">{{ __('onboarding.access.email') }}</span>
                    <span class="block text-sm font-normal muted">{{ __('onboarding.access.email_hint') }}</span>
                </span>
            </button>
            <button type="button" wire:click="pick('pin')" class="choice" aria-pressed="{{ $method === 'pin' ? 'true' : 'false' }}">
                <x-lucide-key-round class="size-6 shrink-0 text-(--color-accent)" aria-hidden="true" />
                <span class="flex-1">
                    <span class="block">{{ __('onboarding.access.pin') }}</span>
                    <span class="block text-sm font-normal muted">{{ __('onboarding.access.pin_hint') }}</span>
                </span>
            </button>
        </div>

        @if ($method === 'email')
            <form wire:submit="useEmail" class="card stack" novalidate>
                <x-ui.field id="email" :label="__('auth.email.label')" :help="__('auth.email.help')" :error="$errors->first('email')">
                    <input id="email" type="email" wire:model="email" class="input" autocomplete="email" inputmode="email" maxlength="254"
                           @error('email') aria-invalid="true" @enderror aria-describedby="email-help">
                </x-ui.field>
                <label class="choice font-normal">
                    <input type="checkbox" wire:model="remember" class="size-5 accent-(--color-accent)">
                    <span>{{ __('auth.remember') }}</span>
                </label>
                <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">{{ __('auth.email.send') }}</button>
            </form>
        @elseif ($method === 'pin')
            <form wire:submit="usePin" class="card stack" novalidate x-data="revealToggle">
                <x-ui.field id="pin" :label="__('auth.pin.new_label')" :help="__('auth.pin.new_help')" :error="$errors->first('pin')">
                    <input id="pin" x-bind:type="inputType" wire:model="pin" class="input input-code" inputmode="numeric" autocomplete="new-password"
                           maxlength="6" pattern="[0-9]{6}" @error('pin') aria-invalid="true" @enderror aria-describedby="pin-help">
                </x-ui.field>
                <x-ui.field id="pinConfirm" :label="__('auth.pin.confirm_label')" :error="$errors->first('pinConfirm')">
                    <input id="pinConfirm" x-bind:type="inputType" wire:model="pinConfirm" class="input input-code" inputmode="numeric"
                           autocomplete="new-password" maxlength="6" pattern="[0-9]{6}">
                </x-ui.field>
                <button type="button" class="btn-link self-start" x-on:click="toggle">
                    <x-lucide-eye class="inline size-4" aria-hidden="true" /> {{ __('auth.pin.show') }}
                </button>
                <label class="choice font-normal">
                    <input type="checkbox" wire:model="remember" class="size-5 accent-(--color-accent)">
                    <span>{{ __('auth.remember') }}</span>
                </label>
                <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">{{ __('onboarding.access.create_pin') }}</button>
            </form>
        @endif
    @endif
</div>

<div class="stack-lg">
    <section class="stack">
        <h1 class="page-title">{{ __('auth.step_up.title') }}</h1>
        <p class="lead">{{ __('auth.step_up.lead') }}</p>
    </section>

    @if ($needsMfa && ! $hasTotp)
        <x-ui.banner variant="warning" icon="shield-check">
            <p>{{ __($forPlatform ? 'auth.step_up.mfa_required' : 'auth.step_up.mfa_required_building') }}</p>
            <a href="{{ route('me.security') }}" class="btn mt-3">{{ __('me.security.setup') }}</a>
        </x-ui.banner>
    @elseif ($hasTotp)
        <form wire:submit="verify" class="card stack" novalidate>
            <x-ui.field id="code" :label="__('auth.step_up.totp_label')" :help="__('auth.step_up.totp_help')" :error="$errors->first('code')">
                <input id="code" wire:model="code" class="input input-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" aria-describedby="code-help">
            </x-ui.field>
            <button type="submit" class="btn btn-primary btn-block">{{ __('auth.step_up.confirm') }}</button>
        </form>
    @elseif ($hasEmail)
        <div class="card stack">
            @if (! $sent)
                <p>{{ __('auth.step_up.email_intro') }}</p>
                <button type="button" wire:click="sendCode" class="btn btn-primary btn-block">{{ __('auth.step_up.send') }}</button>
                @error('code') <span class="field-error" role="alert">{{ $message }}</span> @enderror
            @else
                <form wire:submit="verify" class="stack" novalidate>
                    <x-ui.field id="code" :label="__('auth.email.code_label')" :help="__('auth.step_up.email_sent')" :error="$errors->first('code')">
                        <input id="code" wire:model="code" class="input input-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" aria-describedby="code-help">
                    </x-ui.field>
                    <button type="submit" class="btn btn-primary btn-block">{{ __('auth.step_up.confirm') }}</button>
                </form>
            @endif
        </div>
    @else
        <x-ui.banner variant="warning" icon="mail">{{ __('auth.step_up.needs_email') }}</x-ui.banner>
    @endif

    <p class="text-sm muted">{{ __('auth.step_up.logged') }}</p>
</div>

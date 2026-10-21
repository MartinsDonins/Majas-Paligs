<div class="stack-lg">
    <section class="stack">
        <h1 class="page-title">{{ $offer ? __('auth.passkey.offer_title') : __('auth.step_up.title') }}</h1>
        <p class="lead">{{ $offer ? __('auth.passkey.offer_lead') : ($strong ? __('auth.step_up.lead_strong') : __('auth.step_up.lead')) }}</p>
    </section>

    @if ($offer)
        {{-- Confirmed with a code, no passkey yet: one tap now replaces the code next time. --}}
        <section class="card stack" wire:key="step-up-offer">
            <x-auth.passkey-register :label="__('auth.passkey.offer_add')" />
            <button type="button" wire:click="later" class="btn btn-block" data-testid="passkey-later">{{ __('auth.passkey.later') }}</button>
        </section>
    @elseif ($mustEnrol)
        <x-ui.banner variant="info" icon="shield-check">
            <p>{{ __($forPlatform ? 'auth.step_up.mfa_required' : 'auth.step_up.mfa_required_building') }}</p>
        </x-ui.banner>

        @if ($mayEnrol)
            <section class="card stack" wire:key="step-up-enrol">
                <p>{{ __('auth.passkey.enrol_intro') }}</p>
                <x-auth.passkey-register :label="__('auth.passkey.offer_add')" />
                <label class="choice font-normal">
                    <input type="checkbox" wire:model="trust" class="size-5 accent-(--color-accent)">
                    <span>{{ __('auth.trust.label') }}</span>
                </label>
                <p class="text-sm muted">{{ __('auth.trust.help') }}</p>
            </section>
            <a href="{{ route('me.security') }}" class="btn-link">{{ __('auth.passkey.use_app_instead') }}</a>
        @elseif ($hasEmail)
            <div class="card stack" wire:key="step-up-enrol-email">
                <p>{{ __('auth.passkey.enrol_email_first') }}</p>
                @if (! $sent)
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
    @else
        @if ($hasPasskeys)
            <section class="card stack" wire:key="step-up-passkey">
                <x-auth.passkey-button :primary="true" :label="__('auth.passkey.confirm')" />
                @unless ($hasTotp)
                    <p class="text-sm muted">{{ __('auth.passkey.lost') }}</p>
                @endunless
            </section>
        @endif

        @if ($hasTotp)
            <form wire:submit="verify" class="card stack" novalidate wire:key="step-up-totp">
                @if ($hasPasskeys)
                    <p class="text-sm muted">{{ __('auth.passkey.or_code') }}</p>
                @endif
                <x-ui.field id="code" :label="__('auth.step_up.totp_label')" :help="__('auth.step_up.totp_help')" :error="$errors->first('code')">
                    <input id="code" wire:model="code" class="input input-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" aria-describedby="code-help">
                </x-ui.field>
                <button type="submit" class="btn {{ $hasPasskeys ? '' : 'btn-primary' }} btn-block">{{ __('auth.step_up.confirm') }}</button>
            </form>
        @elseif (! $hasPasskeys && $hasEmail)
            <div class="card stack" wire:key="step-up-email">
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
        @elseif (! $hasPasskeys)
            <x-ui.banner variant="warning" icon="mail">{{ __('auth.step_up.needs_email') }}</x-ui.banner>
        @endif

        @if ($needsMfa && ($hasPasskeys || $hasTotp))
            <label class="choice font-normal" wire:key="step-up-trust">
                <input type="checkbox" wire:model="trust" class="size-5 accent-(--color-accent)" data-testid="trust-device">
                <span>{{ __('auth.trust.label') }}</span>
            </label>
            <p class="text-sm muted">{{ __($strong ? 'auth.trust.help_strong' : 'auth.trust.help') }}</p>
        @endif
    @endif

    <p class="text-sm muted">{{ __('auth.step_up.logged') }}</p>
</div>

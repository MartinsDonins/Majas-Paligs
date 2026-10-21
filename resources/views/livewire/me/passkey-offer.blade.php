<div class="stack-lg">
    <section class="stack">
        <h1 class="page-title">{{ __('me.passkey_offer.title') }}</h1>
        <p class="lead">{{ __('me.passkey_offer.lead') }}</p>
    </section>

    <section class="card stack">
        <ul class="stack text-sm" role="list">
            <li class="flex gap-3"><x-lucide-fingerprint class="size-5 shrink-0 text-(--color-accent)" aria-hidden="true" /> {{ __('me.passkey_offer.point_tap') }}</li>
            <li class="flex gap-3"><x-lucide-shield-check class="size-5 shrink-0 text-(--color-accent)" aria-hidden="true" /> {{ __('me.passkey_offer.point_safe') }}</li>
            <li class="flex gap-3"><x-lucide-mail class="size-5 shrink-0 text-(--color-accent)" aria-hidden="true" /> {{ __('me.passkey_offer.point_fallback') }}</li>
        </ul>
    </section>

    @if ($needsTotp)
        {{-- The account already has an authenticator: adding a passkey needs one code from it first. --}}
        <form wire:submit="verify" class="card stack" novalidate wire:key="offer-totp">
            <p>{{ __('me.passkey_offer.totp_first') }}</p>
            <x-ui.field id="code" :label="__('auth.step_up.totp_label')" :help="__('auth.step_up.totp_help')" :error="$errors->first('code')">
                <input id="code" wire:model="code" class="input input-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" aria-describedby="code-help">
            </x-ui.field>
            <button type="submit" class="btn btn-primary btn-block">{{ __('auth.step_up.confirm') }}</button>
        </form>
    @else
        <section class="card stack" wire:key="offer-add">
            <x-auth.passkey-register :label="__('auth.passkey.offer_add')" />
            <label class="choice font-normal">
                <input type="checkbox" wire:model="trust" class="size-5 accent-(--color-accent)" data-testid="trust-device">
                <span>{{ __('auth.trust.label') }}</span>
            </label>
            <p class="text-sm muted">{{ __('auth.trust.help') }}</p>
        </section>
    @endif

    <button type="button" wire:click="later" class="btn btn-block" data-testid="passkey-later">{{ __('auth.passkey.later') }}</button>
</div>

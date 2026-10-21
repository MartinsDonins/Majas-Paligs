@props(['label' => null, 'primary' => true])
{{-- "Izveidot ieejas atslēgu" (ADR 0006). Alpine `passkeyRegister` (resources/js/app.js) + RegistersPasskey. --}}
<div x-data="passkeyRegister" data-error="{{ __('me.security.passkey_failed') }}" data-exists="{{ __('me.security.passkey_exists') }}"
     {{ $attributes->merge(['class' => 'stack']) }} wire:ignore.self>
    <button type="button" class="btn btn-block {{ $primary ? 'btn-primary' : '' }}" x-show="supported" x-cloak x-on:click="register" x-bind:disabled="busy" data-testid="passkey-register">
        <x-lucide-fingerprint class="size-5" aria-hidden="true" /> {{ $label ?? __('me.security.passkey_add') }}
    </button>
    <p class="text-sm muted" x-show="unsupported" x-cloak>{{ __('me.security.passkey_unsupported') }}</p>
    <p class="field-error" role="alert" x-show="error" x-text="error"></p>
    @error('passkey') <p class="field-error" role="alert" data-testid="passkey-error">{{ $message }}</p> @enderror
</div>

@props(['autofill' => false, 'label' => null, 'primary' => false])
{{-- Passkey sign-in / confirmation (ADR 0006). Alpine `passkeyAuth` (resources/js/app.js) talks to the Livewire
     component's AssertsPasskey methods. Hidden where the browser has no WebAuthn. --}}
<div x-data="passkeyAuth" data-autofill="{{ $autofill ? '1' : '0' }}" data-error="{{ __('auth.passkey.failed') }}"
     x-show="supported" x-cloak {{ $attributes->merge(['class' => 'stack']) }} wire:ignore.self>
    <button type="button" class="btn btn-block {{ $primary ? 'btn-primary' : '' }}" x-on:click="start" x-bind:disabled="busy" data-testid="passkey-button">
        <x-lucide-fingerprint class="size-5" aria-hidden="true" /> {{ $label ?? __('auth.passkey.login') }}
    </button>
    <p class="field-error" role="alert" x-show="error" x-text="error"></p>
    @error('passkey') <p class="field-error" role="alert" data-testid="passkey-error">{{ $message }}</p> @enderror
</div>

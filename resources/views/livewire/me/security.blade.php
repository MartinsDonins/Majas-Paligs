<div class="stack-lg">
    <h1 class="page-title">{{ __('me.security.title') }}</h1>
    <p class="lead">{{ __('me.security.lead') }}</p>

    @if (in_array(session('status'), ['totp_enabled', 'passkey_added', 'passkey_removed', 'device_trusted', 'device_revoked'], true))
        <x-ui.banner variant="info" icon="circle-check">{{ __('me.security.status.'.session('status')) }}</x-ui.banner>
    @endif

    {{-- 1. Passkeys: the simple way in (fingerprint / Face ID / screen lock). --}}
    <section class="card stack" aria-labelledby="pk-h" wire:key="sec-passkeys">
        <h2 id="pk-h" class="text-lg">{{ __('me.security.passkeys_title') }}</h2>
        <p class="text-sm muted">{{ __($elevated ? 'me.security.passkeys_body_admin' : 'me.security.passkeys_body') }}</p>
        @if ($passkeys->isNotEmpty())
            <ul class="stack" role="list">
                @foreach ($passkeys as $passkey)
                    <li class="list-row" wire:key="pk-{{ $passkey->id }}">
                        <span class="flex min-w-0 flex-col gap-1">
                            <span class="flex items-center gap-2 font-semibold"><x-lucide-fingerprint class="size-5 text-(--color-accent)" aria-hidden="true" /> {{ $passkey->name }}</span>
                            <span class="text-sm muted">
                                {{ __('me.security.added_on', ['date' => $passkey->created_at?->timezone('Europe/Riga')->format('d.m.Y')]) }}
                                @if ($passkey->last_used_at)
                                    · {{ __('me.security.used_on', ['date' => $passkey->last_used_at->timezone('Europe/Riga')->format('d.m.Y')]) }}
                                @endif
                            </span>
                        </span>
                        <button type="button" wire:click="removePasskey({{ $passkey->id }})" wire:confirm="{{ __('me.security.passkey_remove_confirm') }}" class="btn btn-danger shrink-0" data-testid="passkey-remove">{{ __('me.security.remove') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif
        <x-auth.passkey-register />
    </section>

    {{-- 2. Trusted devices: no confirmation for 30 days on this device. --}}
    <section class="card stack" aria-labelledby="td-h" wire:key="sec-devices">
        <h2 id="td-h" class="text-lg">{{ __('me.security.devices_title') }}</h2>
        <p class="text-sm muted">{{ __('me.security.devices_body') }}</p>
        @if ($devices->isNotEmpty())
            <ul class="stack" role="list">
                @foreach ($devices as $device)
                    <li class="list-row" wire:key="td-{{ $device->id }}">
                        <span class="flex min-w-0 flex-col gap-1">
                            <span class="flex items-center gap-2 font-semibold">
                                <x-lucide-smartphone class="size-5 shrink-0 text-(--color-accent)" aria-hidden="true" /> {{ $device->label }}
                            </span>
                            <span class="text-sm muted">{{ __('me.security.trusted_until', ['date' => $device->expires_at->timezone('Europe/Riga')->format('d.m.Y')]) }}</span>
                            @if ($device->id === $thisDeviceId)
                                <span><x-ui.status-chip status="approved">{{ __('me.security.this_device') }}</x-ui.status-chip></span>
                            @endif
                        </span>
                        <button type="button" wire:click="revokeDevice({{ $device->id }})" class="btn shrink-0" data-testid="device-revoke">{{ __('me.security.untrust') }}</button>
                    </li>
                @endforeach
            </ul>
            <button type="button" wire:click="revokeAllDevices" wire:confirm="{{ __('me.security.untrust_all_confirm') }}" class="btn btn-block">{{ __('me.security.untrust_all') }}</button>
        @else
            <p class="text-sm">{{ __('me.security.devices_none') }}</p>
        @endif
        @if ($canTrust)
            <button type="button" wire:click="trustThisDevice" class="btn btn-block" data-testid="device-trust">{{ __('auth.trust.label') }}</button>
        @endif
    </section>

    {{-- 3. Authenticator app (fallback second factor). --}}
    <section class="card stack" aria-labelledby="totp-h" wire:key="sec-totp">
        <h2 id="totp-h" class="text-lg">{{ __('me.security.totp_title') }}</h2>
        @if ($enabled)
            <x-ui.status-chip status="approved">{{ __('me.security.enabled') }}</x-ui.status-chip>
            @error('code') <span class="field-error" role="alert">{{ $message }}</span> @enderror
            <button type="button" wire:click="disable" wire:confirm="{{ __('me.security.disable_confirm') }}" class="btn btn-danger btn-block">{{ __('me.security.disable') }}</button>
        @elseif ($pending)
            <p>{{ __('me.security.scan') }}</p>
            @if ($qr)
                <img src="{{ $qr }}" alt="{{ __('me.security.qr_alt') }}" class="mx-auto size-56 rounded-(--radius-sm) bg-white p-2">
            @endif
            <p class="text-sm muted">{{ __('me.security.manual') }}</p>
            <p class="text-center font-bold tnum tracking-widest">{{ $pending }}</p>
            <form wire:submit="confirm" class="stack" novalidate>
                <x-ui.field id="code" :label="__('auth.step_up.totp_label')" :error="$errors->first('code')">
                    <input id="code" wire:model="code" class="input input-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7">
                </x-ui.field>
                <button type="submit" class="btn btn-primary btn-block">{{ __('me.security.confirm') }}</button>
            </form>
        @else
            <p class="text-sm muted">{{ __('me.security.intro') }}</p>
            <button type="button" wire:click="start" class="btn btn-block">{{ __('me.security.setup') }}</button>
        @endif
    </section>

    <a href="{{ $back }}" class="btn btn-block">{{ __('common.back') }}</a>
</div>

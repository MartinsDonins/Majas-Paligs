<div class="stack-lg">
    <h1 class="page-title">{{ __('me.security.title') }}</h1>
    <p class="lead">{{ __('me.security.lead') }}</p>

    @if (session('status') === 'totp_enabled')
        <x-ui.banner variant="info" icon="circle-check">{{ __('me.security.enabled_now') }}</x-ui.banner>
    @endif

    @if ($enabled)
        <section class="card stack">
            <x-ui.status-chip status="approved">{{ __('me.security.enabled') }}</x-ui.status-chip>
            <button type="button" wire:click="disable" wire:confirm="{{ __('me.security.disable_confirm') }}" class="btn btn-danger btn-block">{{ __('me.security.disable') }}</button>
        </section>
    @elseif ($pending)
        <section class="card stack">
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
        </section>
    @else
        <section class="card stack">
            <p>{{ __('me.security.intro') }}</p>
            <button type="button" wire:click="start" class="btn btn-primary btn-block">{{ __('me.security.setup') }}</button>
        </section>
    @endif

    <a href="{{ route('home.redirect') }}" class="btn btn-block">{{ __('common.back') }}</a>
</div>

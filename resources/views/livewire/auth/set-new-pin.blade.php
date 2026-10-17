<div class="stack-lg">
    <section class="stack">
        <h1 class="page-title">{{ __('auth.pin.new_title') }}</h1>
        <p class="lead">{{ __('auth.pin.new_lead') }}</p>
    </section>

    <form wire:submit="save" class="card stack" novalidate x-data="revealToggle">
        <x-ui.field id="pin" :label="__('auth.pin.new_label')" :help="__('auth.pin.new_help')" :error="$errors->first('pin')">
            <input id="pin" x-bind:type="inputType" wire:model="pin" class="input input-code" inputmode="numeric" autocomplete="new-password"
                   maxlength="6" pattern="[0-9]{6}" aria-describedby="pin-help" @error('pin') aria-invalid="true" @enderror>
        </x-ui.field>
        <x-ui.field id="pinConfirm" :label="__('auth.pin.confirm_label')" :error="$errors->first('pinConfirm')">
            <input id="pinConfirm" x-bind:type="inputType" wire:model="pinConfirm" class="input input-code" inputmode="numeric"
                   autocomplete="new-password" maxlength="6" pattern="[0-9]{6}">
        </x-ui.field>
        <button type="button" class="btn-link self-start" x-on:click="toggle">
            <x-lucide-eye class="inline size-4" aria-hidden="true" /> {{ __('auth.pin.show') }}
        </button>
        <button type="submit" class="btn btn-primary btn-block">{{ __('common.save') }}</button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-block">{{ __('common.logout') }}</button>
    </form>
</div>

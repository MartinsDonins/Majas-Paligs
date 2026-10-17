<section class="card stack" aria-labelledby="assist-h">
    <h2 id="assist-h" class="text-lg">{{ __('elder.assisted.title') }}</h2>

    @if ($createdHandle)
        <div class="stack text-center" role="status">
            <x-lucide-circle-check class="mx-auto size-12 text-(--color-success)" aria-hidden="true" />
            <p class="font-semibold">{{ __('elder.assisted.done') }}</p>
            <p class="mono-code tnum">{{ $createdHandle }}</p>
            <p class="text-sm muted">{{ __('onboarding.access.handle_card') }}</p>
            <button type="button" wire:click="restart" class="btn btn-block">{{ __('elder.assisted.next') }}</button>
        </div>
    @elseif (! $resolved)
        <p class="text-sm muted">{{ __('elder.assisted.lead') }}</p>
        <form wire:submit="lookup" class="stack" novalidate>
            <x-ui.field id="assist-code" :label="__('onboarding.code.label')" :error="$errors->first('code')">
                <input id="assist-code" wire:model="code" class="input input-code" autocapitalize="characters" autocomplete="off" spellcheck="false" maxlength="24" placeholder="XXXXX-XXXXX">
            </x-ui.field>
            <button type="submit" class="btn btn-primary btn-block">{{ __('common.continue') }}</button>
        </form>
    @else
        <div class="banner banner-info">
            <x-lucide-hand-helping class="size-5 shrink-0" aria-hidden="true" />
            <div>
                <p class="font-semibold">{{ __('elder.assisted.handover') }}</p>
                <p class="text-xl font-bold tnum">{{ __('onboarding.role.apartment', ['n' => $resolved->apartmentNumber]) }}</p>
            </div>
        </div>
        <form wire:submit="create" class="stack" novalidate x-data="revealToggle">
            <label class="choice font-normal">
                <input type="checkbox" wire:model="itsMe" class="size-5 shrink-0 accent-(--color-accent)">
                <span>{{ __('elder.assisted.its_me') }}</span>
            </label>
            @error('itsMe') <span class="field-error" role="alert">{{ $message }}</span> @enderror

            <fieldset class="stack">
                <legend class="field-label">{{ __('onboarding.role.question') }}</legend>
                @foreach (['owner', 'tenant', 'family'] as $r)
                    <label class="choice">
                        <input type="radio" wire:model="role" value="{{ $r }}" class="size-5 accent-(--color-accent)">
                        <span>{{ __('enum.membership_role.'.$r) }}</span>
                    </label>
                @endforeach
                @error('role') <span class="field-error" role="alert">{{ $message }}</span> @enderror
            </fieldset>

            <x-ui.field id="assist-pin" :label="__('auth.pin.new_label')" :help="__('auth.pin.new_help')" :error="$errors->first('pin')">
                <input id="assist-pin" x-bind:type="inputType" wire:model="pin" class="input input-code" inputmode="numeric" autocomplete="new-password" maxlength="6">
            </x-ui.field>
            <x-ui.field id="assist-pin2" :label="__('auth.pin.confirm_label')" :error="$errors->first('pinConfirm')">
                <input id="assist-pin2" x-bind:type="inputType" wire:model="pinConfirm" class="input input-code" inputmode="numeric" autocomplete="new-password" maxlength="6">
            </x-ui.field>
            <button type="button" class="btn-link self-start" x-on:click="toggle">{{ __('auth.pin.show') }}</button>
            @error('code') <span class="field-error" role="alert">{{ $message }}</span> @enderror
            <button type="submit" class="btn btn-primary btn-block">{{ __('elder.assisted.create') }}</button>
            <button type="button" wire:click="restart" class="btn-link">{{ __('common.cancel') }}</button>
        </form>
    @endif
</section>

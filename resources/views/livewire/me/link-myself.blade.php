{{-- "Piesaistīt mani dzīvoklim" (FIRSTRUN-001): an administrator without an apartment in this house. --}}
<section class="card stack" id="link-myself" aria-labelledby="link-h" data-testid="link-myself">
    <h2 id="link-h" class="text-lg">{{ __('start.link.title') }}</h2>
    <p class="text-sm">{{ $platform ? __('start.link.platform_note') : __('start.link.admin_note') }}</p>

    @if ($refused)
        <x-ui.banner variant="warning" icon="circle-alert" role="alert" data-testid="link-refused">{{ __('start.refused.'.$refused) }}</x-ui.banner>
    @endif

    <form wire:submit="link" class="stack">
        <x-ui.field id="link-apartment" :label="__('start.link.apartment')" :error="$errors->first('apartmentNumber')">
            <input id="link-apartment" type="number" inputmode="numeric" min="1" wire:model="apartmentNumber" class="input tnum" required
                   @error('apartmentNumber') aria-invalid="true" aria-describedby="link-apartment-error" @enderror>
        </x-ui.field>
        <x-ui.field id="link-role" :label="__('start.link.role')">
            <select id="link-role" wire:model="role" class="input">
                @foreach (['owner', 'tenant', 'family'] as $r)
                    <option value="{{ $r }}">{{ __('enum.membership_role.'.$r) }}</option>
                @endforeach
            </select>
        </x-ui.field>
        <button type="submit" class="btn btn-primary btn-block" data-testid="link-submit">
            <x-lucide-house class="size-5" aria-hidden="true" /> {{ __('start.link.button') }}
        </button>
    </form>
</section>

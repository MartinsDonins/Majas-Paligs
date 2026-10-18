<section class="card stack" aria-labelledby="proxy-h">
    <h2 id="proxy-h" class="text-lg">{{ __('elder.proxy.title') }}</h2>
    <p class="text-sm muted">{{ __('elder.proxy.lead') }}</p>

    @if (! $enabled)
        <x-ui.banner variant="info" icon="hammer">{{ __('elder.module_off') }}</x-ui.banner>
    @elseif ($apartments === [])
        <x-ui.empty-state :title="__('elder.proxy.no_section')" />
    @else
        @if ($savedFor)
            <x-ui.banner variant="info" icon="circle-check">{{ __('elder.proxy.saved', ['n' => $savedFor]) }}</x-ui.banner>
        @endif

        <form wire:submit="save" class="space-y-4">
            <x-ui.field id="proxy-apartment" :label="__('elder.proxy.apartment')" :error="$errors->first('apartmentNumber')">
                <select id="proxy-apartment" class="input tnum" wire:model.live="apartmentNumber">
                    <option value="">{{ __('elder.proxy.choose_apartment') }}</option>
                    @foreach ($apartments as $n)
                        <option value="{{ $n }}">{{ __('onboarding.role.apartment', ['n' => $n]) }}</option>
                    @endforeach
                </select>
            </x-ui.field>

            <fieldset>
                <legend class="field-label">{{ __('elder.proxy.source') }}</legend>
                <div class="segmented">
                    @foreach (['phone', 'proxy'] as $s)
                        <label class="{{ $source === $s ? 'font-bold' : '' }}" @if ($source === $s) aria-current="page" @endif>
                            <input type="radio" name="proxy-source" value="{{ $s }}" wire:model.live="source" class="size-4">
                            {{ __('elder.proxy.source_'.$s) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @error('states') <x-ui.banner variant="warning" icon="circle-alert" role="alert">{{ $message }}</x-ui.banner> @enderror

            @foreach ($rooms as $room)
                <fieldset class="room-row" wire:key="proxy-room-{{ $room->code }}">
                    <legend class="px-1 font-semibold">{{ $room->getTranslation('label', app()->getLocale()) }}</legend>
                    <div class="mt-2 grid grid-cols-4 gap-2">
                        @foreach ($statuses as $s)
                            <label class="status-option">
                                <input type="radio" name="proxy-{{ $room->code }}" value="{{ $s->value }}" wire:model="states.{{ $room->code }}">
                                <span class="opt-{{ $s->value }}">{{ svg('lucide-'.$s->icon(), '', ['aria-hidden' => 'true']) }} {{ $s->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach

            @if ($rooms->isNotEmpty())
                <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">
                    <x-lucide-check class="size-5" aria-hidden="true" /> {{ __('common.save') }}
                </button>
            @endif
        </form>
    @endif
    <p class="text-sm muted flex items-center gap-2"><x-lucide-history class="size-4" aria-hidden="true" /> {{ __('elder.logged') }}</p>
</section>

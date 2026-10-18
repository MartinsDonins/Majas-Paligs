<div class="space-y-4">
    @if ($permit === null)
        <p class="muted">{{ __('parking.change.gone') }}</p>
    @elseif ($state === 'pending')
        <div class="space-y-3 text-center" role="status">
            <x-lucide-clock class="mx-auto size-12 text-(--color-accent)" aria-hidden="true" />
            <p class="text-xl font-bold">{{ __('parking.declare.thanks') }}</p>
            <p>{{ __('parking.change.next', ['number' => $permit->number]) }}</p>
        </div>
    @else
        <ol class="space-y-1 text-sm muted">
            <li>1. {{ __('parking.change.step1') }}</li>
            <li>2. {{ __('parking.change.step2', ['number' => $permit->number]) }}</li>
            <li>3. {{ __('parking.change.step3') }}</li>
        </ol>

        @if ($retryAfter)
            <x-parking.retry :seconds="$retryAfter" />
        @endif

        <form x-data="plateForm" data-method="submit" data-keep="1" x-on:submit="submit" class="space-y-4" novalidate>
            <x-ui.plate-input id="change-plate-{{ $permit->id }}" :label="__('parking.change.new_plate')"
                              :error="$state === 'invalid' ? __('parking.plate.invalid') : null" />

            <label class="choice min-h-14 {{ $state === 'confirm_return' ? 'border-(--color-warning)' : '' }}">
                <input type="checkbox" wire:model="returnOld" class="size-6">
                <span>{{ __('parking.change.return_old', ['number' => $permit->number]) }}</span>
            </label>
            @if ($state === 'confirm_return')
                <p class="field-error" role="alert"><x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ __('parking.change.return_required') }}</p>
            @endif

            <button type="submit" class="btn btn-primary btn-block" data-requires-online>
                <x-lucide-send class="size-5" aria-hidden="true" /> {{ __('parking.change.submit') }}
            </button>
        </form>
    @endif
</div>

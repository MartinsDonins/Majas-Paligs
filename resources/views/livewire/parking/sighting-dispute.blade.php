<div class="space-y-4">
    <p class="text-sm muted">{{ __('parking.dispute.lead') }}</p>
    @if (! $canDispute)
        <p class="muted">{{ __('parking.pending_read_only') }}</p>
    @elseif ($state === 'ok')
        <x-ui.banner variant="info" icon="circle-check">{{ __('parking.dispute.thanks') }}</x-ui.banner>
    @else
        @if ($retryAfter)
            <x-parking.retry :seconds="$retryAfter" />
        @endif
        <form x-data="plateForm" data-method="submit" x-on:submit="submit" class="space-y-3" novalidate>
            <x-ui.plate-input id="dispute-plate" :label="__('parking.dispute.plate')" :error="$state === 'invalid' ? __('parking.plate.invalid') : null" />
            <button type="submit" class="btn btn-block" data-requires-online>
                <x-lucide-user-round-check class="size-5" aria-hidden="true" /> {{ __('parking.dispute.submit') }}
            </button>
        </form>
    @endif
</div>

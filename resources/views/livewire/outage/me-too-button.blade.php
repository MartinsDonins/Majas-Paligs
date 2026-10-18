<div class="stack">
    @if ($confirmed)
        <div class="banner" role="status">
            <x-lucide-circle-check class="size-5 shrink-0 text-(--color-success)" aria-hidden="true" />
            <p><span class="font-semibold">{{ __('outage.me_too.done') }}</span> {{ trans_choice('outage.me_too.count', $count, ['count' => $count]) }}</p>
        </div>
    @elseif ($canConfirm)
        <button type="button" wire:click="confirm" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">
            <x-lucide-hand class="size-6" aria-hidden="true" /> {{ __('outage.me_too.button') }}
        </button>
        <p class="text-sm muted text-center">{{ trans_choice('outage.me_too.count', $count, ['count' => $count]) }}</p>
    @else
        <p class="text-sm muted">{{ trans_choice('outage.me_too.count', $count, ['count' => $count]) }}</p>
    @endif
</div>

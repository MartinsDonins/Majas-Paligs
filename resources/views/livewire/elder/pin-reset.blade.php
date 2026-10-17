<section class="card stack" aria-labelledby="pinreset-h">
    <h2 id="pinreset-h" class="text-lg">{{ __('elder.pin.title') }}</h2>

    @if ($tempCode)
        <div class="stack text-center" role="status">
            <p class="font-semibold">{{ __('elder.pin.code_for', ['n' => $resetApartment]) }}</p>
            <p class="mono-code tnum select-all">{{ chunk_split($tempCode, 4, ' ') }}</p>
            <x-ui.banner variant="warning" icon="hand-helping">{{ __('elder.pin.hand_over') }}</x-ui.banner>
            <button type="button" wire:click="done" class="btn btn-primary btn-block">{{ __('elder.pin.done') }}</button>
        </div>
    @else
        <p class="text-sm muted">{{ __('elder.pin.lead') }}</p>
        @error('reset') <x-ui.banner variant="warning" icon="hourglass">{{ $message }}</x-ui.banner> @enderror
        @forelse ($rows as $row)
            <div class="list-row" wire:key="pr-{{ $row['id'] }}">
                <div>
                    <p class="font-bold tnum">{{ __('onboarding.role.apartment', ['n' => $row['apartment']]) }}</p>
                    <p class="text-sm muted">{{ $row['role'] }} @if($row['locked']) · <span class="font-semibold text-(--color-warning)">{{ __('elder.pin.locked') }}</span> @endif</p>
                </div>
                <button type="button" wire:click="issue({{ $row['id'] }})" wire:confirm="{{ __('elder.pin.confirm', ['n' => $row['apartment']]) }}" class="btn">{{ __('elder.pin.reset') }}</button>
            </div>
        @empty
            <x-ui.empty-state :title="__('elder.pin.empty')" />
        @endforelse
    @endif
</section>

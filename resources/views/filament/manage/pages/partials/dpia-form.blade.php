{{-- DPIA-lite checklist (ROUND2-SPEC §3.5.3): eight items, each an answer + note (≤ 500); items 1–5 pre-filled from the
     existing controls; completion needs every item answered and the residual-risk acceptance ticked. --}}
@php($readOnly = ! $edit || ! $document->isDraft())
<form wire:submit="saveAnswers" class="grid gap-4" data-testid="dpia-form">
    <p class="text-sm">{{ __('document.disclaimer') }}</p>
    @foreach (\App\Services\Documents\DpiaLite::ITEMS as $item => $allowed)
        <fieldset class="grid gap-2 rounded-lg border border-gray-200 p-3 dark:border-white/10" wire:key="dpia-{{ $item }}">
            <legend class="px-1 text-sm font-semibold">{{ $loop->iteration }}. {{ __('dpia.item.'.$item) }}</legend>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('dpia.help.'.$item) }}</p>
            @if (is_array($allowed))
                <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                    @foreach ($allowed as $option)
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" wire:model="answers.{{ $item }}.answer" value="{{ $option }}" @disabled($readOnly) data-testid="dpia-{{ $item }}-{{ $option }}">
                            {{ __('dpia.answer.'.$option) }}
                        </label>
                    @endforeach
                </div>
            @elseif ($allowed === 'date')
                <input type="date" wire:model="answers.{{ $item }}.answer" class="w-48 rounded-lg border border-gray-300 p-2 text-sm dark:border-white/20 dark:bg-white/5" @disabled($readOnly)>
            @else
                <input type="text" wire:model="answers.{{ $item }}.answer" maxlength="200" class="rounded-lg border border-gray-300 p-2 text-sm dark:border-white/20 dark:bg-white/5" @disabled($readOnly) data-testid="dpia-{{ $item }}-text">
            @endif
            <label class="grid gap-1 text-sm">
                <span>{{ __('dpia.note') }}</span>
                <textarea wire:model="answers.{{ $item }}.note" rows="2" maxlength="{{ \App\Services\Documents\DpiaLite::NOTE_MAX }}" class="rounded-lg border border-gray-300 p-2 dark:border-white/20 dark:bg-white/5" @disabled($readOnly)></textarea>
            </label>
            @if ($item === 'residual')
                <label class="inline-flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" wire:model="answers.residual.accepted" @disabled($readOnly) data-testid="dpia-accept">
                    {{ __('dpia.accept') }}
                </label>
            @endif
        </fieldset>
    @endforeach

    @unless ($readOnly)
        <div class="flex flex-wrap gap-2">
            <x-filament::button type="submit" color="gray" icon="heroicon-o-check">{{ __('common.save') }}</x-filament::button>
            <x-filament::button type="button" wire:click="completeDpia" icon="heroicon-o-check-badge" data-testid="dpia-complete">{{ __('opening.dpia.complete') }}</x-filament::button>
        </div>
    @endunless
</form>

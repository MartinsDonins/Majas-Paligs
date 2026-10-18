<section class="card stack" aria-labelledby="digest-h">
    <h2 id="digest-h" class="text-lg">{{ __('digest.card.title') }}</h2>
    <p class="text-sm muted">{{ __('digest.card.lead') }}</p>

    @if ($sections->count() > 1)
        <x-ui.field id="digest-section" :label="__('elder.notice.section')">
            <select id="digest-section" class="input tnum" wire:model.live="stairwell">
                @foreach ($sections as $s)
                    <option value="{{ $s->number }}">{{ __('heating.stairwell_tab', ['n' => $s->number]) }}</option>
                @endforeach
            </select>
        </x-ui.field>
    @endif

    @if ($text)
        <pre class="whitespace-pre-wrap rounded-(--radius-md) border border-(--color-line) bg-(--color-surface-2) p-3 text-sm" style="font-family: inherit">{{ $text }}</pre>
        <div class="grid gap-2 sm:grid-cols-2">
            <div x-data="copyText" data-text="{{ $text }}">
                <button type="button" x-on:click="copy" class="btn btn-block">
                    <x-lucide-copy class="size-5" aria-hidden="true" />
                    <span x-show="copied" x-cloak>{{ __('digest.card.copied') }}</span>
                    <span x-show="! copied">{{ __('digest.card.copy') }}</span>
                </button>
            </div>
            <a href="{{ $shareUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-block">
                <x-lucide-share-2 class="size-5" aria-hidden="true" /> {{ __('digest.card.share') }}
            </a>
        </div>
        <x-ui.privacy-note>{{ __('digest.card.privacy') }}</x-ui.privacy-note>
    @else
        <x-ui.empty-state :title="__('digest.card.empty')" />
    @endif
</section>

{{-- DocumentEditor (ROUND2-SPEC §3.4): locked blocks are grey read-only cards, editable blocks textareas; LV and RU side
     by side on ≥ 1024 px, tabs on phones. Status chips are icon + word. --}}
<div class="grid gap-4" x-data="{ tab: 'lv' }" data-testid="document-editor">
    <div class="flex flex-wrap items-center gap-2">
        <x-filament::badge :color="$document->isDraft() ? 'warning' : 'success'" :icon="$document->isDraft() ? 'heroicon-o-pencil-square' : 'heroicon-o-lock-closed'">
            {{ $document->status->label() }}
        </x-filament::badge>
        <span class="text-sm">{{ $document->kind->label() }} · v{{ $document->version }}</span>
        <span class="text-sm text-gray-600 dark:text-gray-300">{{ __('document.disclaimer') }}</span>
    </div>

    <div class="flex gap-2 lg:hidden" role="tablist" aria-label="{{ __('document.languages') }}">
        @foreach (['lv' => 'Latviešu', 'ru' => 'Русский'] as $loc => $label)
            <button type="button" role="tab" class="rounded-lg border px-3 py-2 text-sm"
                    x-on:click="tab = '{{ $loc }}'" x-bind:aria-selected="tab === '{{ $loc }}'"
                    x-bind:class="tab === '{{ $loc }}' ? 'border-primary-600 font-semibold' : 'border-gray-300'">{{ $label }}</button>
        @endforeach
    </div>

    @if ($html !== null)
        <div class="grid gap-4 lg:grid-cols-2" data-testid="document-preview">
            @foreach (['lv', 'ru'] as $loc)
                <article class="prose max-w-none rounded-lg border border-gray-200 p-4 text-sm dark:prose-invert dark:border-white/10"
                         lang="{{ $loc }}" x-bind:class="tab === '{{ $loc }}' ? '' : 'hidden lg:block'">
                    {!! $html[$loc] !!}
                </article>
            @endforeach
        </div>
    @else
        <form wire:submit="save" class="grid gap-4 lg:grid-cols-2">
            @foreach (['lv', 'ru'] as $loc)
                <div class="grid content-start gap-3" lang="{{ $loc }}" x-bind:class="tab === '{{ $loc }}' ? '' : 'hidden lg:grid'" data-testid="editor-{{ $loc }}">
                    <p class="text-sm font-semibold">{{ $loc === 'lv' ? 'Latviešu' : 'Русский' }}</p>
                    @foreach ($segments[$loc] as $segment)
                        @if ($segment['type'] === 'edit')
                            <label class="grid gap-1" wire:key="edit-{{ $loc }}-{{ $segment['key'] }}">
                                <span class="inline-flex items-center gap-1 text-sm font-medium">
                                    <x-filament::icon icon="heroicon-o-pencil" class="size-4" />{{ __('document.editable_block') }}
                                </span>
                                <textarea wire:model="{{ $loc }}.{{ $segment['key'] }}" rows="4" maxlength="{{ $max }}"
                                          class="w-full rounded-lg border border-gray-300 p-2 text-sm dark:border-white/20 dark:bg-white/5"
                                          @disabled(! $canEdit) data-testid="block-{{ $loc }}-{{ $segment['key'] }}"></textarea>
                            </label>
                        @else
                            <div class="rounded-lg bg-gray-100 p-3 text-sm text-gray-800 dark:bg-white/5 dark:text-gray-200" wire:key="locked-{{ $loc }}-{{ $segment['key'] }}">
                                <span class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-gray-600 dark:text-gray-400">
                                    <x-filament::icon icon="heroicon-o-lock-closed" class="size-3" />{{ __('document.locked_block') }}
                                </span>
                                <div class="whitespace-pre-line break-words">{{ trim($segment['text']) }}</div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endforeach

            <div class="flex flex-wrap gap-2 lg:col-span-2">
                @if ($canEdit)
                    <x-filament::button type="submit" icon="heroicon-o-check" data-testid="editor-save">{{ __('common.save') }}</x-filament::button>
                @endif
            </div>
        </form>
    @endif

    <div>
        <x-filament::button color="gray" size="sm" wire:click="togglePreview" :icon="$html === null ? 'heroicon-o-eye' : 'heroicon-o-pencil-square'">
            {{ $html === null ? __('document.preview') : __('document.back_to_edit') }}
        </x-filament::button>
    </div>
</div>

<x-filament-panels::page>
    @php($current = $this->current())
    <x-filament::section :heading="__('admin.notice.current')" icon="heroicon-o-document-check">
        @if ($current)
            <p class="text-sm">
                {{ __('admin.notice.published_at', ['date' => $current->published_at?->timezone('Europe/Riga')->format('d.m.Y H:i')]) }}
                · {{ __('admin.notice.covers') }}: {{ collect($current->covers_modules)->map(fn ($m) => __('enum.module.'.$m))->join(', ') }}
            </p>
            <div class="mt-3 flex gap-2">
                <x-filament::button tag="a" color="gray" :href="route('building.privacy', \App\Tenancy\CurrentBuilding::get())" target="_blank">{{ __('admin.notice.view_public') }}</x-filament::button>
                <x-filament::button color="danger" wire:click="withdraw" wire:confirm="{{ __('admin.notice.withdraw_confirm') }}">{{ __('admin.notice.withdraw') }}</x-filament::button>
            </div>
        @else
            <p class="text-sm">{{ __('admin.notice.none') }}</p>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('admin.notice.adopt')" icon="heroicon-o-document-text">
        @php($templates = $this->templates())
        @if ($templates->isEmpty())
            <p class="text-sm">{{ __('admin.notice.no_template') }}</p>
        @else
            <div class="grid gap-4">
                <label class="grid gap-1 text-sm font-medium">
                    {{ __('admin.notice.template') }}
                    <select wire:model.live="templateId" class="fi-select-input rounded-lg border border-gray-300 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                        @foreach ($templates as $t)
                            <option value="{{ $t->id }}">{{ $t->version }} ({{ collect($t->covers_modules)->join(', ') }})</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex gap-2">
                    <x-filament::button size="sm" :color="$previewLocale === 'lv' ? 'primary' : 'gray'" wire:click="setLocale('lv')">LV</x-filament::button>
                    <x-filament::button size="sm" :color="$previewLocale === 'ru' ? 'primary' : 'gray'" wire:click="setLocale('ru')">RU</x-filament::button>
                </div>
                <div class="whitespace-pre-line rounded-lg border border-gray-200 p-4 text-sm dark:border-white/10">{{ $this->preview() }}</div>
                <div>
                    <x-filament::button wire:click="publish" wire:confirm="{{ __('admin.notice.publish_confirm') }}" icon="heroicon-o-check">{{ __('admin.notice.publish') }}</x-filament::button>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

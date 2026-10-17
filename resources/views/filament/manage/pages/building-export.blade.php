<x-filament-panels::page>
    <x-filament::section :heading="__('admin.export.title')" icon="heroicon-o-arrow-down-tray">
        <p class="text-sm">{{ __('admin.export.help') }}</p>
        <ul class="mt-2 list-disc ps-5 text-sm">
            <li>{{ __('admin.export.includes') }}</li>
            <li>{{ __('admin.export.excludes') }}</li>
        </ul>
        <div class="mt-4">
            <x-filament::button wire:click="requestExport" icon="heroicon-o-arrow-path">{{ __('admin.export.request') }}</x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('admin.export.recent')">
        <div class="grid gap-2 text-sm">
            @forelse ($this->exports() as $e)
                <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <span>{{ $e['created'] }}</span>
                    <x-filament::badge :color="$e['status'] === 'ready' ? 'success' : ($e['status'] === 'failed' ? 'danger' : 'gray')">{{ __('enum.export_status.'.$e['status']) }}</x-filament::badge>
                    @if ($e['url'])
                        <x-filament::button tag="a" size="sm" :href="$e['url']" icon="heroicon-o-arrow-down-tray">{{ __('admin.export.download') }}</x-filament::button>
                    @endif
                </div>
            @empty
                <p>{{ __('admin.export.none') }}</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>

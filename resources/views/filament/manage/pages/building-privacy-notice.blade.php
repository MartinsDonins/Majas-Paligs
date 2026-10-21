{{-- Read-only notice history (ROUND2-SPEC §3.2): publishing moved to the opening wizard. --}}
<x-filament-panels::page>
    @php($current = $this->current())
    <x-filament::section :heading="__('admin.notice.current')" icon="heroicon-o-document-check">
        @if ($current)
            <p class="text-sm">
                {{ __('admin.notice.published_at', ['date' => $current->published_at?->timezone('Europe/Riga')->format('d.m.Y H:i')]) }}
                · {{ __('admin.notice.covers') }}: {{ collect($current->covers_modules)->map(fn ($m) => __('enum.module.'.$m))->join(', ') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-filament::button tag="a" color="gray" :href="route('building.privacy', \App\Tenancy\CurrentBuilding::get())" target="_blank">{{ __('admin.notice.view_public') }}</x-filament::button>
            </div>
        @else
            <p class="text-sm">{{ __('admin.notice.none') }}</p>
        @endif
        <p class="mt-3 text-sm"><a href="{{ $this->wizardUrl() }}" class="text-primary-600 underline">{{ __('opening.notice.manage_in_wizard') }}</a></p>
    </x-filament::section>

    <x-filament::section :heading="__('opening.notice.history')" icon="heroicon-o-clock">
        <ul class="grid gap-2 text-sm" data-testid="notice-history">
            @forelse ($this->history() as $n)
                <li class="rounded-lg border border-gray-200 p-2 dark:border-white/10" wire:key="notice-{{ $n->id }}">
                    {{ $n->published_at?->timezone('Europe/Riga')->format('d.m.Y H:i') }}
                    · {{ collect($n->covers_modules)->map(fn ($m) => __('enum.module.'.$m))->join(', ') }}
                    · {{ $n->withdrawn_at === null ? __('opening.notice.current_word') : __('opening.notice.withdrawn_at', ['date' => $n->withdrawn_at->timezone('Europe/Riga')->format('d.m.Y')]) }}
                </li>
            @empty
                <li>{{ __('admin.notice.none') }}</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>

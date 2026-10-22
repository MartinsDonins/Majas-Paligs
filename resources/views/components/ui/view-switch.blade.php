{{-- "Iedzīvotāja skats | Admin skats" (FIRSTRUN-001, App\Support\ViewSwitch): a two-part toggle in the top bar of the
     resident app and of both Filament panels, only for someone who can administer something. The current view is the
     pressed half (aria-current); the other half is a plain link through /view/{mode} (remembers the choice, keeps the
     house). Short words below 640 px so it fits next to the house pill and LV/RU at 320 px. --}}
@props(['current', 'building' => null, 'compact' => false, 'residentHref' => null, 'adminHref' => null, 'icons' => true])
{{-- icons=false: Filament panels (DisableBladeIconComponents) — Filament's own heroicons instead of Lucide. --}}
@php
    $items = [
        'resident' => ['icon' => 'house', 'heroicon' => 'heroicon-o-home', 'href' => $residentHref ?? \App\Support\ViewSwitch::url('resident', $building)],
        'admin' => ['icon' => 'shield-check', 'heroicon' => 'heroicon-o-shield-check', 'href' => $adminHref ?? \App\Support\ViewSwitch::url('admin', $building)],
    ];
@endphp
<nav {{ $attributes->merge(['class' => 'flex shrink-0 items-center rounded-full border border-(--color-line) bg-(--color-surface) p-1']) }} aria-label="{{ __('start.view.label') }}" data-testid="view-switch" data-view="{{ $current }}">
    @foreach ($items as $mode => $item)
        @if ($mode === $current)
            <span aria-current="page"
                  class="{{ $compact ? 'min-h-9 px-2' : 'min-h-12 px-3' }} inline-flex grow items-center justify-center gap-1 rounded-full bg-(--color-accent) text-sm font-bold text-(--color-accent-ink)">
                @if ($icons)
                    <x-dynamic-component :component="'lucide-'.$item['icon']" class="size-4 shrink-0" aria-hidden="true" />
                @else
                    <x-filament::icon :icon="$item['heroicon']" class="size-4 shrink-0" />
                @endif
                <span class="sm:hidden">{{ __('start.view.'.$mode.'_short') }}</span>
                <span class="hidden sm:inline">{{ __('start.view.'.$mode) }}</span>
            </span>
        @else
            <a href="{{ $item['href'] }}" data-view-target="{{ $mode }}"
               class="{{ $compact ? 'min-h-9 px-2' : 'min-h-12 px-3' }} inline-flex grow items-center justify-center gap-1 rounded-full text-sm font-bold text-(--color-fg) no-underline">
                @if ($icons)
                    <x-dynamic-component :component="'lucide-'.$item['icon']" class="size-4 shrink-0" aria-hidden="true" />
                @else
                    <x-filament::icon :icon="$item['heroicon']" class="size-4 shrink-0" />
                @endif
                <span class="sm:hidden">{{ __('start.view.'.$mode.'_short') }}</span>
                <span class="hidden sm:inline">{{ __('start.view.'.$mode) }}</span>
            </a>
        @endif
    @endforeach
</nav>

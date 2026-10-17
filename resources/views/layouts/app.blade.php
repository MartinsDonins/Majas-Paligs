@props(['title' => null, 'active' => 'home'])
@php
    use App\Tenancy\CurrentBuilding;
    $building = CurrentBuilding::find();
    $settings = $building?->settings;
    $showHeating = $settings !== null && ($settings->moduleEnabled('heating') || $settings->moduleEnabled('outages'));
    $showParking = $settings !== null && $settings->moduleEnabled('parking');
@endphp
{{-- Resident app shell (DESIGN-SYSTEM §4): sticky top bar, module-dependent bottom nav (3–4 items, labels always
     visible), offline banner, footer with version + privacy + "not an emergency channel". --}}
<x-layouts::base :title="$title">
    <div x-data="connectivity">
        <div x-show="offline" x-cloak class="banner banner-warning sticky top-0 z-40" role="status" style="border-radius:0">
            <x-lucide-wifi-off class="size-5 shrink-0" aria-hidden="true" />
            <span>{{ __('shell.offline') }}</span>
        </div>
    </div>

    <header class="sticky top-0 z-30 border-b border-(--color-line) bg-(--color-bg)/95 backdrop-blur-sm">
        <div class="mx-auto flex h-[var(--topbar-h)] w-full max-w-[40rem] items-center justify-between gap-2 px-4">
            @if ($building)
                <livewire:shell.building-switcher />
            @else
                <a href="{{ route('landing') }}" class="flex items-center gap-2 font-bold text-(--color-fg) no-underline">
                    <x-brand-mark class="size-7" />
                    <span>{{ __('common.app_name') }}</span>
                </a>
            @endif
            <x-ui.language-toggle />
        </div>
    </header>

    <main id="main" class="page">
        {{ $slot }}
        <x-ui.footer :building="$building" />
    </main>

    @if ($building)
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-(--color-line) bg-(--color-surface)"
             style="padding-bottom: env(safe-area-inset-bottom)" aria-label="{{ __('nav.label') }}">
            <ul class="mx-auto flex h-[var(--bottomnav-h)] max-w-[40rem] items-stretch justify-around px-2">
                <x-ui.nav-item :href="route('building.home', $building)" icon="house" :label="__('nav.home')" :active="$active === 'home'" />
                @if ($showHeating)
                    <x-ui.nav-item href="#" icon="thermometer" :label="__('nav.heating')" :active="$active === 'heating'" :disabled="true" />
                @endif
                @if ($showParking)
                    <x-ui.nav-item href="#" icon="car" :label="__('nav.parking')" :active="$active === 'parking'" :disabled="true" />
                @endif
                <x-ui.nav-item :href="route('building.me', $building)" icon="ellipsis" :label="__('nav.more')" :active="$active === 'more'" />
            </ul>
        </nav>
    @endif
</x-layouts::base>

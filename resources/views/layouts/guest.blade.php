@props(['title' => null])
{{-- Onboarding / login shell: no bottom navigation, language toggle always visible, calm centred column. --}}
<x-layouts::base :title="$title">
    <div x-data="connectivity">
        <div x-show="offline" x-cloak class="banner banner-warning" role="status" style="border-radius:0">
            <x-lucide-wifi-off class="size-5 shrink-0" aria-hidden="true" />
            <span>{{ __('shell.offline') }}</span>
        </div>
    </div>

    <header class="mx-auto flex w-full max-w-[40rem] flex-wrap items-center justify-between gap-3 px-4 pt-4">
        <a href="{{ route('landing') }}" class="flex items-center gap-2 font-bold text-(--color-fg) no-underline">
            <x-brand-mark class="size-8" />
            <span>{{ __('common.app_name') }}</span>
        </a>
        <x-ui.language-toggle />
    </header>

    <main id="main" class="page page-narrow">
        {{ $slot }}
    </main>

    <x-ui.footer :compact="true" />
</x-layouts::base>

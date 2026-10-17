@props(['title' => null])
{{-- Inline "Kas redz šo?" disclosure with a lock icon (trust signal, DESIGN-SYSTEM §1.5). --}}
<div x-data="disclosure" class="rounded-(--radius-sm) border border-dashed border-(--color-line) px-3 py-2 text-sm">
    <button type="button" class="flex min-h-11 w-full items-center gap-2 text-left font-semibold text-(--color-fg)"
            x-on:click="toggle" x-bind:aria-expanded="expanded">
        <x-lucide-lock class="size-4 shrink-0 text-(--color-accent)" aria-hidden="true" />
        <span class="flex-1">{{ $title ?? __('privacy_note.who_sees') }}</span>
        <x-lucide-chevron-down class="size-4 shrink-0" aria-hidden="true" />
    </button>
    <div x-show="expanded" x-cloak class="pb-2 text-(--color-fg-muted)">
        {{ $slot }}
    </div>
</div>

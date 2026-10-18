@props(['id', 'title', 'trigger' => null, 'open' => false])
{{--
    Bottom sheet (DESIGN-SYSTEM §5): Esc + visible "Aizvērt", max 90vh, radius-lg top. A real modal: x-trap keeps
    Tab inside, `.inert` hides the page behind from screen readers, `.noscroll` locks the background scroll.
    The wrapper is `display: contents` (review fix) so a `.sticky-action` trigger sticks within the PAGE, not within
    a wrapper box that ends right after it.
    Slots: `trigger` (the opener; calls show) and the default slot (sheet body).
--}}
<div x-data="sheet" x-on:keydown="onKeydown" data-open="{{ $open ? '1' : '0' }}" {{ $attributes->merge(['class' => 'contents']) }}>
    {{ $trigger }}
    <div x-show="open" x-cloak class="sheet-backdrop" x-on:click="hide"></div>
    <div x-show="open" x-cloak x-trap.noscroll.inert="open" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-h" class="sheet">
        <div class="mx-auto max-w-[40rem] space-y-4">
            <div class="sheet-handle" aria-hidden="true"></div>
            <div class="flex items-start justify-between gap-3">
                <h2 id="{{ $id }}-h" class="text-lg">{{ $title }}</h2>
                <button type="button" x-on:click="hide" class="btn">{{ __('common.close') }}</button>
            </div>
            {{ $slot }}
        </div>
    </div>
</div>

@props(['href', 'icon', 'label', 'active' => false, 'disabled' => false])
{{-- min-w-0 everywhere: at 320px + 200 % text the three items share the width instead of overflowing it. --}}
<li class="flex min-w-0 flex-1">
    <a href="{{ $disabled ? '#' : $href }}"
       @if($active) aria-current="page" @endif
       @if($disabled) aria-disabled="true" tabindex="-1" @endif
       class="flex min-h-12 min-w-0 flex-1 flex-col items-center justify-center gap-1 text-sm font-semibold leading-tight no-underline {{ $active ? 'text-(--color-accent)' : 'text-(--color-fg-muted)' }} {{ $disabled ? 'opacity-50' : '' }}">
        <span class="flex h-8 w-14 max-w-full items-center justify-center rounded-full {{ $active ? 'bg-(--color-accent-soft)' : '' }}">
            <x-dynamic-component :component="'lucide-'.$icon" class="size-6" aria-hidden="true" />
        </span>
        <span class="max-w-full truncate px-0.5">{{ $label }}</span>
    </a>
</li>

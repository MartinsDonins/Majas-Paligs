@props(['href', 'icon', 'label', 'active' => false, 'disabled' => false])
<li class="flex flex-1">
    <a href="{{ $disabled ? '#' : $href }}"
       @if($active) aria-current="page" @endif
       @if($disabled) aria-disabled="true" tabindex="-1" @endif
       class="flex min-h-12 flex-1 flex-col items-center justify-center gap-1 text-xs font-semibold no-underline {{ $active ? 'text-(--color-accent)' : 'text-(--color-fg-muted)' }} {{ $disabled ? 'opacity-50' : '' }}">
        <span class="flex h-8 w-14 items-center justify-center rounded-full {{ $active ? 'bg-(--color-accent-soft)' : '' }}">
            <x-dynamic-component :component="'lucide-'.$icon" class="size-6" aria-hidden="true" />
        </span>
        <span>{{ $label }}</span>
    </a>
</li>

@props(['href' => '#', 'icon', 'label', 'hint' => null, 'disabled' => false])
<a href="{{ $disabled ? '#' : $href }}" @if($disabled) aria-disabled="true" @endif
   {{ $attributes->merge(['class' => 'big-action'.($disabled ? ' opacity-60' : '')]) }}>
    <x-dynamic-component :component="'lucide-'.$icon" aria-hidden="true" />
    <span>
        <span class="block">{{ $label }}</span>
        @if ($hint)
            <span class="mt-1 block text-sm font-normal text-(--color-fg-muted)">{{ $hint }}</span>
        @endif
    </span>
</a>

@props(['variant' => 'info', 'icon' => 'info', 'role' => 'status'])
<div {{ $attributes->merge(['class' => 'banner banner-'.$variant, 'role' => $role]) }}>
    <x-dynamic-component :component="'lucide-'.$icon" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
    <div class="min-w-0 flex-1">{{ $slot }}</div>
</div>

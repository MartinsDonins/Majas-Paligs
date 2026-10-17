@props(['status', 'size' => 'md'])
{{-- Icon + word for every status (never colour alone). --}}
@php
    $map = [
        'approved' => ['icon' => 'circle-check', 'class' => 'chip-success'],
        'pending' => ['icon' => 'clock', 'class' => 'chip-warning'],
        'rejected' => ['icon' => 'circle-x', 'class' => ''],
        'ended' => ['icon' => 'circle-minus', 'class' => ''],
        'open' => ['icon' => 'circle-check', 'class' => 'chip-success'],
        'closed' => ['icon' => 'lock', 'class' => 'chip-warning'],
        'hypothesis' => ['icon' => 'circle-help', 'class' => ''],
        'resident_confirmed' => ['icon' => 'users', 'class' => 'chip-accent'],
        'manager_confirmed' => ['icon' => 'badge-check', 'class' => 'chip-accent'],
        'drawing_confirmed' => ['icon' => 'badge-check', 'class' => 'chip-success'],
    ];
    $conf = $map[$status] ?? ['icon' => 'info', 'class' => ''];
@endphp
<span {{ $attributes->merge(['class' => 'chip '.$conf['class'].($size === 'sm' ? ' text-xs' : '')]) }}>
    <x-dynamic-component :component="'lucide-'.$conf['icon']" class="size-4" aria-hidden="true" />
    <span>{{ $slot->isEmpty() ? __('status.'.$status) : $slot }}</span>
</span>

@props(['plate', 'country' => 'LV'])
{{-- Latvian plate look (DESIGN-SYSTEM §5) — ONLY for the user's own vehicles; lookups never echo a plate like this. --}}
<span {{ $attributes->merge(['class' => 'plate-badge']) }} aria-label="{{ __('parking.plate_aria', ['plate' => implode(' ', str_split($plate))]) }}">
    <span class="eu" aria-hidden="true">{{ $country }}</span>
    <span class="num" aria-hidden="true">{{ $plate }}</span>
</span>

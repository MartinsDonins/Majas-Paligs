{{-- Brand mark: petrol panel-house silhouette with one highlighted window (DESIGN-SYSTEM §9). --}}
<svg {{ $attributes->merge(['viewBox' => '0 0 48 48', 'role' => 'img', 'aria-hidden' => 'true']) }} xmlns="http://www.w3.org/2000/svg">
    <rect x="0" y="0" width="48" height="48" rx="11" fill="#0B4F55"/>
    <rect x="11" y="9" width="26" height="31" rx="2" fill="#EEF7F7"/>
    @foreach ([13, 18, 23, 28, 33] as $y)
        @foreach ([14, 20, 26, 32] as $x)
            <rect x="{{ $x - 0.5 }}" y="{{ $y }}" width="3" height="2.6" rx=".6" fill="{{ $x === 26 && $y === 23 ? '#E69F00' : '#76B6BB' }}"/>
        @endforeach
    @endforeach
    <rect x="21" y="36" width="6" height="4" rx=".8" fill="#0B4F55"/>
</svg>

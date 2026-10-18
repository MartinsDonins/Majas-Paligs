<?php

declare(strict_types=1);

namespace App\Livewire\Parking;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Kas redz šo?" for parking (DESIGN-SYSTEM §6.7): who sees a plate (officer + admin only; neighbours only "ir
 * sarakstā / nav"), that it is stored encrypted, that every reveal is logged and visible to the data subject.
 * `context` = lookup | plate | sighting.
 */
final class PrivacyBox extends Component
{
    #[Locked]
    public string $context = 'plate';

    public function mount(string $context = 'plate'): void
    {
        $this->context = in_array($context, ['lookup', 'plate', 'sighting'], true) ? $context : 'plate';
    }

    public function render(): View
    {
        return view('livewire.parking.privacy-box');
    }
}

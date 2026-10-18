<?php

declare(strict_types=1);

namespace App\Livewire\Parking;

use App\Models\LegalNotice;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "Pašreizējais noteikums" banner (PARK-007): the newest platform legal notice `parking.riga.current_rule` whose
 * valid_from has passed (editable in the platform panel), with a calm fallback sentence.
 */
final class LegalBanner extends Component
{
    public function render(): View
    {
        $notice = LegalNotice::query()
            ->where('key', (string) config('majas.parking.legal_notice_key', 'parking.riga.current_rule'))
            ->where('valid_from', '<=', now())
            ->orderByDesc('valid_from')
            ->first();

        $locale = app()->getLocale();

        return view('livewire.parking.legal-banner', [
            'text' => $notice?->getTranslation('body', $locale, false) ?: $notice?->getTranslation('body', 'lv', false) ?: __('parking.legal.fallback'),
            'since' => $notice?->valid_from,
            'source' => $notice?->getAttribute('source_url'),
        ]);
    }
}

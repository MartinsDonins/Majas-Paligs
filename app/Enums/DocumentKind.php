<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kinds of house documents made from platform masters (ROUND2-SPEC §3.3, GOV-011).
 */
enum DocumentKind: string
{
    use HasLabel;

    /** Art. 28 data processing agreement between the house (controller) and CoreDigify (processor). */
    case Dpa = 'dpa';

    /** Scope extension of a recorded DPA (e.g. + roster, + parking). */
    case DpaAnnex = 'dpa_annex';

    /** The building privacy notice (Art. 13 / 14). */
    case PrivacyNotice = 'privacy_notice';

    /** Short risk assessment (parking; reusable for the roster). */
    case DpiaLite = 'dpia_lite';

    /** Neutral house decision template on parking rules (download only). */
    case ParkingDecision = 'parking_decision';

    protected static function labelPrefix(): string
    {
        return 'document_kind';
    }

    /**
     * DPA-like documents go to CoreDigify and are recorded by the platform.
     */
    public function isAgreement(): bool
    {
        return $this === self::Dpa || $this === self::DpaAnnex;
    }
}

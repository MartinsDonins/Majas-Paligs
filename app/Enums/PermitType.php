<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Parking permit type (MVP-SPEC C.1): main permits are plate-bound, guest permits are not.
 */
enum PermitType: string
{
    use HasLabel;

    case Main = 'main';
    case Guest = 'guest';

    protected static function labelPrefix(): string
    {
        return 'permit_type';
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a permit row came from (ROUND2-SPEC §6.1): issued by staff, the permit issuer's CSV list, or a plate change.
 */
enum PermitSource: string
{
    use HasLabel;

    case Officer = 'officer';
    case Import = 'import';
    case ChangeVehicle = 'change_vehicle';

    protected static function labelPrefix(): string
    {
        return 'permit_source';
    }
}

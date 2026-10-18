<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * plate_lookups.reason_code (MVP-SPEC C.1): resident reason chips + declaration / sighting (they consume the same quota).
 */
enum LookupReason: string
{
    use HasLabel;

    case Blocking = 'blocking';
    case UnknownCar = 'unknown_car';
    case OwnCheck = 'own_check';
    case Other = 'other';
    case Declaration = 'declaration';
    case Sighting = 'sighting';

    protected static function labelPrefix(): string
    {
        return 'lookup_reason';
    }
}

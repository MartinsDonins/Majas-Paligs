<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Vehicle status (MVP-SPEC C.1): resident declarations always start pending; only an officer/admin action sets active.
 */
enum VehicleStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Removed = 'removed';

    protected static function labelPrefix(): string
    {
        return 'vehicle_status';
    }
}

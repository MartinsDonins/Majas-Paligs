<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a permit left the active state (MVP-SPEC C.1/C.5 — VehicleRemovalService reasons).
 */
enum PermitStatusReason: string
{
    use HasLabel;

    case VehicleRemoved = 'vehicle_removed';
    case AccountDeleted = 'account_deleted';
    case Retention = 'retention';
    case Officer = 'officer';

    protected static function labelPrefix(): string
    {
        return 'permit_status_reason';
    }
}

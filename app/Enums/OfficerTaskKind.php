<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Parking officer queue items (MVP-SPEC C.1) — payload holds ids only, never a plate.
 */
enum OfficerTaskKind: string
{
    use HasLabel;

    case PermitWithoutVehicle = 'permit_without_vehicle';
    case VehicleConflict = 'vehicle_conflict';
    case VehicleVerify = 'vehicle_verify';

    protected static function labelPrefix(): string
    {
        return 'officer_task_kind';
    }
}

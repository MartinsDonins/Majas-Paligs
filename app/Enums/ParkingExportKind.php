<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Parking export kinds (MVP-SPEC C.5): police list (enforcement mode plate_list|both) and manager list.
 */
enum ParkingExportKind: string
{
    use HasLabel;

    case Police = 'police';
    case Manager = 'manager';

    protected static function labelPrefix(): string
    {
        return 'parking_export_kind';
    }
}

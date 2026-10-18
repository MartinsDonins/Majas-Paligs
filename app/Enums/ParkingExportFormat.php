<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Parking export file formats.
 */
enum ParkingExportFormat: string
{
    use HasLabel;

    case Csv = 'csv';
    case Pdf = 'pdf';

    protected static function labelPrefix(): string
    {
        return 'parking_export_format';
    }
}

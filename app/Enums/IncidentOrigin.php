<?php

declare(strict_types=1);

namespace App\Enums;

enum IncidentOrigin: string
{
    use HasLabel;

    case Resident = 'resident';
    case Notice = 'notice';

    protected static function labelPrefix(): string
    {
        return 'incident_origin';
    }
}

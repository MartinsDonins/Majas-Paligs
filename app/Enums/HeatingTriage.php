<?php

declare(strict_types=1);

namespace App\Enums;

enum HeatingTriage: string
{
    use HasLabel;

    case AirSuspected = 'air_suspected';
    case ValveClosed = 'valve_closed';
    case WholeFlat = 'whole_flat';
    case Unknown = 'unknown';

    protected static function labelPrefix(): string
    {
        return 'heating_triage';
    }
}

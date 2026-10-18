<?php

declare(strict_types=1);

namespace App\Enums;

enum RiserCheckResult: string
{
    use HasLabel;

    case Normal = 'normal';
    case AirVented = 'air_vented';
    case Fixed = 'fixed';
    case Escalated = 'escalated';

    protected static function labelPrefix(): string
    {
        return 'riser_check_result';
    }
}

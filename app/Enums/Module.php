<?php

declare(strict_types=1);

namespace App\Enums;

enum Module: string
{
    use HasLabel;

    case Account = 'account';
    case Core = 'core';
    case Heating = 'heating';
    case Outages = 'outages';
    case Parking = 'parking';

    protected static function labelPrefix(): string
    {
        return 'module';
    }
}

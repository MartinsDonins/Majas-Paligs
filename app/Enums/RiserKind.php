<?php

declare(strict_types=1);

namespace App\Enums;

enum RiserKind: string
{
    use HasLabel;

    case Heating = 'heating';
    case ColdWater = 'cold_water';
    case HotWater = 'hot_water';
    case HotWaterCirculation = 'hot_water_circulation';
    case Sewage = 'sewage';

    protected static function labelPrefix(): string
    {
        return 'riser_kind';
    }
}

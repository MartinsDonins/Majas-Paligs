<?php

declare(strict_types=1);

namespace App\Enums;

enum BuildingStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    protected static function labelPrefix(): string
    {
        return 'building_status';
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a vehicle row came from (MVP-SPEC C.1).
 */
enum VehicleSource: string
{
    use HasLabel;

    case SelfDeclared = 'self';
    case Officer = 'officer';
    case SeedList = 'seed_list';

    protected static function labelPrefix(): string
    {
        return 'vehicle_source';
    }
}

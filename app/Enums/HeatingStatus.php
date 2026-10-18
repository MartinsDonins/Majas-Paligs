<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A resident's radiator report state (DESIGN-SYSTEM §2.3). "Under" = cold or lukewarm.
 */
enum HeatingStatus: string
{
    use HasLabel;

    case Cold = 'cold';
    case Lukewarm = 'lukewarm';
    case Warm = 'warm';
    case Overheated = 'overheated';

    protected static function labelPrefix(): string
    {
        return 'heating_status';
    }

    public function isUnder(): bool
    {
        return $this === self::Cold || $this === self::Lukewarm;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cold => 'snowflake',
            self::Lukewarm => 'thermometer-snowflake',
            self::Warm => 'thermometer-sun',
            self::Overheated => 'flame',
        };
    }
}

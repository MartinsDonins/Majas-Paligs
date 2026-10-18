<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outage kinds (MVP-SPEC B.1). Phase 2 adds lift / lights / door_lock / other.
 */
enum IncidentKind: string
{
    use HasLabel;

    case WaterCold = 'water_cold';
    case WaterHot = 'water_hot';
    case HeatingNone = 'heating_none';

    protected static function labelPrefix(): string
    {
        return 'incident_kind';
    }

    /**
     * Riser kind used to resolve the outage scope (B.2a).
     */
    public function riserKind(): RiserKind
    {
        return match ($this) {
            self::WaterCold => RiserKind::ColdWater,
            self::WaterHot => RiserKind::HotWater,
            self::HeatingNone => RiserKind::Heating,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::WaterCold => 'droplet-off',
            self::WaterHot => 'droplet-off',
            self::HeatingNone => 'heater',
        };
    }
}

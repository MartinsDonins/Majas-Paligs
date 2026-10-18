<?php

declare(strict_types=1);

namespace App\Gdpr;

use RuntimeException;

/**
 * Thrown by the parking model guards (Vehicle, Permit, Declaration, Sighting, PlateLookup, …) below gate L1 —
 * catches seeders, console, imports and tinker, not only the UI (MVP-SPEC C.0).
 */
final class ParkingGateClosed extends RuntimeException
{
    public function __construct(public readonly int $required = 1, public readonly int $level = 0)
    {
        parent::__construct("Parking gate L{$required} is closed (building is at L{$level}).");
    }
}

<?php

declare(strict_types=1);

namespace App\Gdpr;

use Illuminate\Database\Eloquent\Model;

/**
 * Model guard (MVP-SPEC C.0 enforcement 2): saving or deleting a parking row below gate L1 throws
 * ParkingGateClosed from ANY path (policy, Filament, import, seeder, console, tinker). Retention deletes run as
 * majas_retention through the query builder (prune:*), and VehicleRemovalService erasures are never blocked.
 *
 * @mixin Model
 */
trait GuardedByParkingGate
{
    public static function bootGuardedByParkingGate(): void
    {
        static::saving(static function (): void {
            ParkingGate::assertLevel(1);
        });

        static::deleting(static function (): void {
            ParkingGate::assertLevel(1);
        });
    }
}

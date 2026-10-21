<?php

declare(strict_types=1);

namespace App\Gdpr;

use Illuminate\Database\Eloquent\Model;

/**
 * Model guard for roster rows (ROUND2-SPEC §3.1 enforcement 2): saving or deleting while gate L-R is closed throws
 * RosterGateClosed from ANY path. Retention deletes (majas_retention, query builder) and account erasure (query
 * builder) are never blocked, like GuardedByParkingGate.
 *
 * @mixin Model
 */
trait GuardedByRosterGate
{
    public static function bootGuardedByRosterGate(): void
    {
        static::saving(static function (): void {
            RosterGate::assertOpen();
        });

        static::deleting(static function (): void {
            RosterGate::assertOpen();
        });
    }
}

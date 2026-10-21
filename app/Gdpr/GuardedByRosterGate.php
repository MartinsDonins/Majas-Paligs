<?php

declare(strict_types=1);

namespace App\Gdpr;

use Illuminate\Database\Eloquent\Model;

/**
 * Model guard (ROUND2-SPEC §3.1, enforcement 2): saving or deleting a roster row while gate L-R is closed throws
 * RosterGateClosed from ANY path (policy, Filament, import job, seeder, console, tinker). Retention deletes
 * (prune:roster, majas_retention) and GDPR erasure (AccountEraser) delete through the query builder and are never
 * blocked — a data subject's erasure must work even while the house's roster is closed.
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

<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use App\Enums\PermitStatusReason;
use App\Models\Building;
use App\Services\Parking\VehicleRemovalService;
use App\Tenancy\BuildingIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:vehicles (PLANNING §5, MVP-SPEC C.5) — per building, as majas_retention, every removal goes through
 * VehicleRemovalService (reason `retention`), so a bound active main permit is revoked with an officer task first:
 *
 * - rejected / removed vehicles 30 days after their last change;
 * - "Vai šis auto vēl aktuāls?" unanswered: reconfirm_due_at passed more than 30 days ago;
 * - membership end: a self-declared vehicle whose apartment has had no pending/approved member for 30 days;
 * - permit end: a vehicle that had permits, none still effective, the last one ended more than 30 days ago.
 */
#[AsCommand(name: 'prune:vehicles')]
final class PruneVehicles extends Command
{
    protected $signature = 'prune:vehicles';

    protected $description = 'Remove vehicles 30 days after permit end, membership end or an unanswered re-confirmation.';

    public function handle(VehicleRemovalService $removal): int
    {
        $db = DB::connection('pgsql_retention');
        $total = 0;

        BuildingIterator::each(function (Building $building) use ($db, $removal, &$total): void {
            $ids = $db->select(<<<'SQL'
                SELECT v.id FROM vehicles v
                 WHERE v.building_id = ?
                   AND (
                        (v.status IN ('rejected', 'removed') AND v.updated_at < now() - interval '30 days')
                     OR (v.reconfirm_due_at IS NOT NULL AND v.reconfirm_due_at < now() - interval '30 days')
                     OR (v.source = 'self'
                         AND NOT EXISTS (SELECT 1 FROM apartment_memberships m
                                          WHERE m.apartment_id = v.apartment_id AND m.status IN ('pending', 'approved'))
                         AND (SELECT max(m.ended_at) FROM apartment_memberships m WHERE m.apartment_id = v.apartment_id)
                             < now() - interval '30 days')
                     OR (EXISTS (SELECT 1 FROM permits p WHERE p.building_id = v.building_id AND p.vehicle_id = v.id)
                         AND NOT EXISTS (SELECT 1 FROM permits p
                                          WHERE p.building_id = v.building_id AND p.vehicle_id = v.id AND p.status = 'active'
                                            AND (p.valid_to IS NULL OR p.valid_to >= current_date))
                         AND (SELECT max(CASE WHEN p.status = 'active' THEN p.valid_to ELSE p.updated_at::date END)
                                FROM permits p WHERE p.building_id = v.building_id AND p.vehicle_id = v.id)
                             < current_date - 30)
                   )
                SQL, [$building->id]);

            foreach ($ids as $row) {
                $removal->remove((string) $row->id, PermitStatusReason::Retention, $db, actorRole: 'system');
                $total++;
            }
        });

        $this->info("Removed {$total} vehicles.");

        return self::SUCCESS;
    }
}

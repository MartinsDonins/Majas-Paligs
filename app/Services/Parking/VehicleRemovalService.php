<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Audit\AuditLogger;
use App\Enums\OfficerTaskKind;
use App\Enums\PermitStatus;
use App\Enums\PermitStatusReason;
use App\Enums\PermitType;
use App\Tenancy\CurrentBuilding;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY code path that deletes a vehicle (PARK-001.3, MVP-SPEC C.5): "Dzēst manu numuru" (MyPermits),
 * AccountEraser, prune:vehicles (majas_retention connection) and the officer delete.
 *
 * One transaction: lock the active main permits bound to the vehicle → revoke them with status_reason → open a
 * "Caurlaide bez auto" officer task per permit → delete the vehicle (the FK nulls vehicle_id on the now non-active
 * permits, so the "main permit needs a vehicle" CHECK always holds) → audit `vehicle.remove`.
 * Works through the query builder on the given connection, so an erasure is never blocked by the parking gate
 * (GDPR erasure must always be possible) and the retention role needs no Eloquent model events.
 */
final class VehicleRemovalService
{
    /**
     * @return int number of main permits revoked (0 when the vehicle does not exist in the current building)
     */
    public function remove(
        string $vehicleId,
        PermitStatusReason $reason,
        ?ConnectionInterface $connection = null,
        ?int $actorUserId = null,
        ?string $actorRole = null,
    ): int {
        $db = $connection ?? DB::connection();
        $buildingId = CurrentBuilding::id();

        $revoked = $db->transaction(static function () use ($db, $buildingId, $vehicleId, $reason, $connection, $actorUserId, $actorRole): ?int {
            $vehicle = $db->table('vehicles')
                ->where('building_id', $buildingId)
                ->where('id', $vehicleId)
                ->first(['id', 'declared_by_user_id']);

            if ($vehicle === null) {
                return null;
            }

            $permitIds = $db->table('permits')
                ->where('building_id', $buildingId)
                ->where('vehicle_id', $vehicleId)
                ->where('status', PermitStatus::Active->value)
                ->where('type', PermitType::Main->value)
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($permitIds !== []) {
                $db->table('permits')
                    ->where('building_id', $buildingId)
                    ->whereIn('id', $permitIds)
                    ->update(['status' => PermitStatus::Revoked->value, 'status_reason' => $reason->value, 'updated_at' => now()]);

                $db->table('parking_officer_tasks')->insert(array_map(static fn (int $id): array => [
                    'building_id' => $buildingId,
                    'kind' => OfficerTaskKind::PermitWithoutVehicle->value,
                    'permit_id' => $id,
                    'status' => 'open',
                    'created_at' => now(),
                ], $permitIds));
            }

            $db->table('vehicles')->where('building_id', $buildingId)->where('id', $vehicleId)->delete();

            AuditLogger::record(
                action: 'vehicle.remove',
                subject: ['vehicle', $vehicleId],
                subjectUserIds: $vehicle->declared_by_user_id !== null ? [(int) $vehicle->declared_by_user_id] : [],
                reasonCode: $reason->value,
                data: ['revoked_permits' => count($permitIds)],
                actorRole: $actorRole,
                actorUserId: $actorUserId,
                connection: $connection,
            );

            return count($permitIds);
        });

        if ($revoked === null) {
            return 0;
        }

        Completeness::forget($buildingId);

        return $revoked;
    }

    /**
     * Account deletion (AccountEraser): every vehicle the user declared in the current building.
     */
    public function removeAllDeclaredBy(int $userId): int
    {
        $ids = DB::table('vehicles')
            ->where('building_id', CurrentBuilding::id())
            ->where('declared_by_user_id', $userId)
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            $this->remove((string) $id, PermitStatusReason::AccountDeleted, actorUserId: $userId, actorRole: 'self');
            $count++;
        }

        return $count;
    }
}

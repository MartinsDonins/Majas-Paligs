<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Audit\AuditLogger;
use App\Casts\Bytea;
use App\Notifications\Alerts;
use App\Tenancy\CurrentBuilding;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * plates:rekey (MVP-SPEC C.1 Rotation): moves the CURRENT building to plate key version + 1 in ONE transaction
 * holding pg_advisory_xact_lock('plates:'||building_id) — re-seal ciphertext and recompute the blind index of every
 * vehicle, sighting and guest-permit observation (VaultContext::TABLES), bump settings.plate_index_key_version, rebuild plate_sighting_stats. All rows of the building
 * switch together, so the partial UNIQUE on active bidx stays valid; a duplicate aborts that building's transaction
 * and alerts the platform admin. Old plate_lookups rows keep their old bidx and age out (6 months).
 */
final class PlateRekeyService
{
    public function __construct(
        private readonly PlateVault $vault,
        private readonly SightingStats $stats,
        private readonly Alerts $alerts,
    ) {}

    /**
     * @return array{from: int, to: int, vehicles: int, sightings: int, observations: int}
     */
    public function rekey(): array
    {
        $building = CurrentBuilding::get();
        $buildingId = (int) $building->id;

        try {
            $result = DB::transaction(function () use ($building, $buildingId): array {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['plates:'.$buildingId]);
                $building->refresh();
                $from = $building->settings->plateIndexKeyVersion;
                $to = $from + 1;

                $counts = [];
                foreach (VaultContext::TABLES as $table) {
                    $counts[$table] = 0;
                    $rows = DB::table($table)->where('building_id', $buildingId)->get(['id', 'plate_ciphertext', 'plate_key_version']);

                    foreach ($rows as $row) {
                        $context = new VaultContext($buildingId, $table, (string) $row->id);
                        $plate = $this->vault->open((string) Bytea::decode($row->plate_ciphertext), $context, (int) $row->plate_key_version);
                        $sealed = $this->vault->seal($plate, $context, $to);

                        DB::table($table)->where('building_id', $buildingId)->where('id', $row->id)->update([
                            'plate_ciphertext' => Bytea::literal($sealed->ciphertext),
                            'plate_bidx' => Bytea::literal($sealed->bidx),
                            'plate_key_version' => $to,
                        ]);
                        $counts[$table]++;
                    }
                }

                $building->forceFill(['settings' => $building->settings->with(['plate_index_key_version' => $to])])->save();
                $this->stats->rebuild();

                AuditLogger::record('plates.rekey', $building, data: ['from' => $from, 'to' => $to, 'vehicles' => $counts['vehicles'], 'sightings' => $counts['sightings'], 'observations' => $counts['permit_plate_observations']]);

                return ['from' => $from, 'to' => $to, 'vehicles' => $counts['vehicles'], 'sightings' => $counts['sightings'], 'observations' => $counts['permit_plate_observations']];
            });
        } catch (QueryException|PlateVaultException $e) {
            $this->alerts->platform('plates-rekey-failed', 'run', 1, 1);

            throw $e;
        }

        Completeness::forget($buildingId);

        return $result;
    }
}

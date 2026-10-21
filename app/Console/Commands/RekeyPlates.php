<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Building;
use App\Services\Parking\PlateRekeyService;
use App\Tenancy\BuildingIterator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * plates:rekey {building?} — move one building (slug) or every building to the next plate key version, one
 * transaction per building (MVP-SPEC C.1). For a master-key rotation set PLATE_ENC_KEY to the new master and
 * PLATE_ENC_KEY_PREVIOUS to the old one, run this for all buildings, then remove PLATE_ENC_KEY_PREVIOUS.
 */
#[AsCommand(name: 'plates:rekey')]
final class RekeyPlates extends Command
{
    protected $signature = 'plates:rekey {building? : Building slug (default: all buildings)}';

    protected $description = 'Re-seal plates and recompute blind indexes with the next key version (per building, atomic).';

    public function handle(PlateRekeyService $rekey): int
    {
        $slug = $this->argument('building');
        $failed = 0;
        $done = 0;

        BuildingIterator::each(function (Building $building) use ($slug, $rekey, &$failed, &$done): void {
            if (is_string($slug) && $slug !== '' && $building->slug !== $slug) {
                return;
            }

            try {
                $r = $rekey->rekey();
                $done++;
                $this->info("{$building->slug}: v{$r['from']} → v{$r['to']} ({$r['vehicles']} vehicles, {$r['sightings']} sightings, {$r['observations']} observations)");
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$building->slug}: re-key aborted — ".$e::class);
            }
        });

        if ($done === 0 && $failed === 0) {
            $this->warn('No building matched.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

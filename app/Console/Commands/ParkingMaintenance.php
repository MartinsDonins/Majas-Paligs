<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PermitStatus;
use App\Gdpr\ParkingGate;
use App\Models\Building;
use App\Models\Permit;
use App\Services\Parking\SightingStats;
use App\Tenancy\BuildingIterator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * parking:maintain — hourly, as majas_app, per building at gate L1: active permits past valid_to become `expired`
 * and the plate_sighting_stats projection is rebuilt (the repeat flag decays after 30 days without sightings).
 */
#[AsCommand(name: 'parking:maintain')]
final class ParkingMaintenance extends Command
{
    protected $signature = 'parking:maintain';

    protected $description = 'Expire ended permits and rebuild the sighting statistics (decay of the repeat flag).';

    public function handle(SightingStats $stats): int
    {
        BuildingIterator::each(function (Building $building) use ($stats): void {
            if (! ParkingGate::status($building)->l1()) {
                return;
            }

            $today = CarbonImmutable::now('Europe/Riga')->toDateString();
            $expired = Permit::query()->active()->whereNotNull('valid_to')->where('valid_to', '<', $today)
                ->update(['status' => PermitStatus::Expired->value, 'updated_at' => now()]);
            $plates = $stats->rebuild();

            if ($expired > 0 || $plates > 0) {
                $this->info("{$building->slug}: {$expired} permits expired, {$plates} plates in statistics.");
            }
        });

        return self::SUCCESS;
    }
}

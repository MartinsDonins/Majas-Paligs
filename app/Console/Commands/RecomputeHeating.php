<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Building;
use App\Services\Heating\RiserStatusProjector;
use App\Tenancy\BuildingIterator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * heating:recompute — hourly refresh of the heating projections of every building with the heating module, so
 * ad-hoc reports drop out of the rolling view when they expire (7 days) even without new reports.
 */
#[AsCommand(name: 'heating:recompute')]
final class RecomputeHeating extends Command
{
    protected $signature = 'heating:recompute';

    protected $description = 'Recompute riser/column heating projections of every building.';

    public function handle(RiserStatusProjector $projector): int
    {
        $count = 0;

        BuildingIterator::each(static function (Building $building) use ($projector, &$count): void {
            if (! $building->settings->moduleEnabled('heating')) {
                return;
            }
            $projector->recompute();
            $count++;
        });

        $this->info("Recomputed {$count} buildings.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Heating\RiserStatusProjector;
use App\Tenancy\Jobs\RestoreBuildingContext;
use App\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recompute the heating projections of one building (MVP-SPEC B.2 rule 7): unique per building and debounced
 * 10 s — a burst of reports, hides, checks or disputes triggers one recompute.
 */
final class RecomputeRiserStatus implements ShouldBeUnique, ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const DEBOUNCE_SECONDS = 10;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $buildingId) {}

    /**
     * Debounced dispatch after the surrounding transaction commits.
     */
    public static function debounce(int $buildingId): void
    {
        self::dispatch($buildingId)->delay(now()->addSeconds(self::DEBOUNCE_SECONDS))->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'riser-status:'.$this->buildingId;
    }

    public function tenantBuildingId(): int
    {
        return $this->buildingId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RestoreBuildingContext];
    }

    public function handle(RiserStatusProjector $projector): void
    {
        $projector->recompute();
    }
}

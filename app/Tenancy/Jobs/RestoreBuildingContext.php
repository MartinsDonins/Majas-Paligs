<?php

declare(strict_types=1);

namespace App\Tenancy\Jobs;

use App\Models\Building;
use App\Tenancy\CurrentBuilding;
use Closure;

/**
 * Job middleware for TenantAware jobs: loads the serialized building and runs the job inside runFor().
 * Allow-listed CurrentBuilding caller (PLANNING §4.2 item 5).
 */
final class RestoreBuildingContext
{
    public function handle(TenantAware $job, Closure $next): mixed
    {
        $building = Building::query()->findOrFail($job->tenantBuildingId());

        return CurrentBuilding::runFor($building, static fn () => $next($job));
    }
}

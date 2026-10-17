<?php

declare(strict_types=1);

namespace App\Tenancy\Jobs;

/**
 * A queued job that runs inside one building context. The building id is serialized with the job and
 * restored by the RestoreBuildingContext job middleware (PLANNING §4.2).
 */
interface TenantAware
{
    public function tenantBuildingId(): int;
}

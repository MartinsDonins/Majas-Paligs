<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Building;

/**
 * Per-request holder of the current building. Bound as a scoped instance (flushed between queue jobs and
 * at request termination). Only CurrentBuilding touches it.
 *
 * @internal
 */
final class CurrentBuildingState
{
    public ?Building $building = null;

    /** True when the context was established by set() (request-scoped), false for runFor() scopes. */
    public bool $requestScoped = false;
}

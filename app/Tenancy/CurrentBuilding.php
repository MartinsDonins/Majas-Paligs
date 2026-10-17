<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Building;
use App\Tenancy\Exceptions\BuildingContextAlreadySet;
use App\Tenancy\Exceptions\MissingBuildingContext;

/**
 * The current tenant (building) of this unit of work (PLANNING §4.2, MVP-SPEC A.5).
 *
 * - set() is a no-op for the same building and throws BuildingContextAlreadySet for another one.
 * - runFor() sets, runs and restores the previous context — also on exception.
 * - set()/runFor() callers are allow-listed (Arch/TenancyArchTest); forget() only in the four sanctioned classes.
 */
final class CurrentBuilding
{
    public static function set(Building $building): void
    {
        $state = self::state();

        if ($state->building !== null) {
            if ((int) $state->building->getKey() === (int) $building->getKey()) {
                return;
            }

            throw BuildingContextAlreadySet::create((int) $state->building->getKey(), (int) $building->getKey());
        }

        $state->building = $building;
        $state->requestScoped = true;
    }

    /**
     * End of an HTTP request (kernel terminate): a context established by set() never outlives its request.
     * runFor() scopes restore themselves and are left alone.
     */
    public static function endRequest(): void
    {
        $state = self::state();

        if ($state->requestScoped) {
            $state->building = null;
            $state->requestScoped = false;
        }
    }

    /**
     * Run $callback with $building as the current building and restore the previous context afterwards.
     *
     * @template T
     *
     * @param  callable(Building): T  $callback
     * @return T
     */
    public static function runFor(Building $building, callable $callback): mixed
    {
        $state = self::state();
        $previous = $state->building;
        $previousScoped = $state->requestScoped;
        $state->building = $building;
        $state->requestScoped = false;

        try {
            return $callback($building);
        } finally {
            $state->building = $previous;
            $state->requestScoped = $previousScoped;
        }
    }

    public static function get(): Building
    {
        $building = self::state()->building;

        if ($building === null) {
            throw new MissingBuildingContext('No current building context.');
        }

        return $building;
    }

    public static function id(): int
    {
        return (int) self::get()->getKey();
    }

    public static function has(): bool
    {
        return self::state()->building !== null;
    }

    /**
     * The current building or null (never throws).
     */
    public static function find(): ?Building
    {
        return self::state()->building;
    }

    /**
     * Clear the context. Sanctioned classes only (Arch/TenancyArchTest).
     */
    public static function forget(): void
    {
        $state = self::state();
        $state->building = null;
        $state->requestScoped = false;
    }

    private static function state(): CurrentBuildingState
    {
        return app(CurrentBuildingState::class);
    }
}

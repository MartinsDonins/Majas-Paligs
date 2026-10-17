<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\BuildingStatus;
use App\Models\Building;

/**
 * SANCTIONED cross-building iteration (PLANNING §4.2 item 3) for scheduler/platform jobs that must visit
 * every building (retention, digests). Iterates building ids and sets CurrentBuilding for each via runFor;
 * never queries tenant rows itself.
 */
final class BuildingIterator
{
    /**
     * @param  callable(Building): void  $callback
     */
    public static function each(callable $callback, bool $includeArchived = false): void
    {
        $query = Building::query()->orderBy('id');

        if (! $includeArchived) {
            $query->where('status', '!=', BuildingStatus::Archived->value);
        }

        $query->chunkById(50, static function ($buildings) use ($callback): void {
            foreach ($buildings as $building) {
                CurrentBuilding::runFor($building, static function (Building $b) use ($callback): void {
                    $callback($b);
                });
            }
        });
    }
}

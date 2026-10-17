<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Building;
use App\Tenancy\Exceptions\MissingBuildingContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant model trait: applies BuildingScope, auto-fills building_id from CurrentBuilding and refuses to
 * write a row of another building than the current one.
 *
 * @mixin Model
 */
trait BelongsToBuilding
{
    public static function bootBelongsToBuilding(): void
    {
        static::addGlobalScope(new BuildingScope);

        static::creating(static function (self $model): void {
            if (! CurrentBuilding::has()) {
                throw MissingBuildingContext::forModel(static::class);
            }

            $current = CurrentBuilding::id();

            if ($model->getAttribute('building_id') === null) {
                $model->setAttribute('building_id', $current);
            }

            if ((int) $model->getAttribute('building_id') !== $current) {
                throw new MissingBuildingContext('Refusing to create a ['.static::class.'] row for another building.');
            }
        });

        static::updating(static function (self $model): void {
            self::assertInContext($model);

            if ($model->isDirty('building_id')) {
                throw new MissingBuildingContext('building_id is immutable.');
            }
        });

        static::deleting(static function (self $model): void {
            self::assertInContext($model);
        });
    }

    private static function assertInContext(self $model): void
    {
        if (! CurrentBuilding::has() || (int) $model->getOriginal('building_id') !== CurrentBuilding::id()) {
            throw new MissingBuildingContext('Refusing to write a ['.static::class.'] row outside its building context.');
        }
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * Query without the building scope. Sanctioned classes only (Arch/TenancyArchTest).
     *
     * @return Builder<static>
     */
    public static function withoutBuildingScope(): Builder
    {
        return static::query()->withoutGlobalScope(BuildingScope::class);
    }
}

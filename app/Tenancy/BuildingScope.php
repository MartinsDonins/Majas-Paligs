<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Tenancy\Exceptions\MissingBuildingContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope of every tenant model: WHERE building_id = current building. Throws when no building is
 * set (fail closed). Lifting it is allowed only in the sanctioned classes (PLANNING §4.2).
 *
 * @implements Scope<Model>
 */
final class BuildingScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! CurrentBuilding::has()) {
            throw MissingBuildingContext::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('building_id'), CurrentBuilding::id());
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\Concerns;

use App\Models\Apartment;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-section least privilege in the manage panel (review fix, GDPR data minimisation): a section elder who is not
 * building_admin sees only rows of their own stairwells — lists, navigation badges and record actions alike.
 */
trait ElderSectionScope
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected static function limitToElderSections(Builder $query, string $column = 'apartment_id'): Builder
    {
        $user = auth()->user();
        $building = CurrentBuilding::get();

        if (! $user instanceof User) {
            return $query->whereRaw('false');
        }

        if ($user->isBuildingAdmin($building)) {
            return $query;
        }

        $sections = $user->elderSectionIds($building);

        return $column === 'section_id'
            ? $query->whereIn($query->qualifyColumn('section_id'), $sections)
            : $query->whereIn($query->qualifyColumn($column), Apartment::query()->whereIn('section_id', $sections)->select('id'));
    }
}

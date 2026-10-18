<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SurveyRound;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * SurveyRoundPolicy (MVP-SPEC B.5): manage = building_admin (any scope, incl. the whole building = null),
 * section_elder only for rounds whose section_ids ⊆ their own stairwells.
 */
final class SurveyRoundPolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    public function view(User $user, SurveyRound $round): bool
    {
        return $this->manages($user, $round->section_ids);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    /**
     * May the user create / save a round with this scope (null = whole building)?
     *
     * @param  list<int>|null  $sectionIds
     */
    public function scope(User $user, ?array $sectionIds): bool
    {
        return $this->manages($user, $sectionIds);
    }

    public function update(User $user, SurveyRound $round): bool
    {
        return $this->manages($user, $round->section_ids);
    }

    public function delete(User $user, SurveyRound $round): bool
    {
        return $this->manages($user, $round->section_ids) && $round->status->value === 'draft' && ! $round->reports()->exists();
    }

    /**
     * @param  list<int>|null  $sectionIds
     */
    private function manages(User $user, ?array $sectionIds): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($sectionIds === null || $sectionIds === []) {
            return false;
        }

        $b = $this->building();

        return $b !== null && ! $user->isTombstone() && array_diff($sectionIds, $user->elderSectionIds($b)) === [];
    }
}

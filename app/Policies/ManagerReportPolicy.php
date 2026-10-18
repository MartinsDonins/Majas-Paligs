<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ManagerReport;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * ManagerReportPolicy (MVP-SPEC B.5): generate / download / mark sent — building_admin any scope; section_elder
 * only when section_ids ⊆ their own stairwells (an elder's report never includes another stairwell).
 */
final class ManagerReportPolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    public function view(User $user, ManagerReport $report): bool
    {
        return $this->covers($user, $report->section_ids);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    /**
     * @param  list<int>  $sectionIds
     */
    public function generate(User $user, array $sectionIds): bool
    {
        return $sectionIds !== [] && $this->covers($user, $sectionIds);
    }

    public function download(User $user, ManagerReport $report): bool
    {
        return $this->covers($user, $report->section_ids);
    }

    public function update(User $user, ManagerReport $report): bool
    {
        return $this->covers($user, $report->section_ids);
    }

    public function delete(User $user, ManagerReport $report): bool
    {
        return false;
    }

    /**
     * @param  list<int>  $sectionIds
     */
    private function covers(User $user, array $sectionIds): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $b = $this->building();

        return $b !== null && $sectionIds !== [] && ! $user->isTombstone()
            && array_diff($sectionIds, $user->elderSectionIds($b)) === [];
    }
}

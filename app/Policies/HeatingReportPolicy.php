<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BuildingRoleType;
use App\Gdpr\CoreGate;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\BuildingRole;
use App\Models\HeatingReport;
use App\Models\Section;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * HeatingReportPolicy (MVP-SPEC B.5).
 * - create: approved member of THAT apartment (gate L-core open, heating module on).
 * - proxy: section_elder or volunteer of the apartment's stairwell (never another stairwell).
 * - viewApartments (per-apartment / per-floor data): building_admin any stairwell; section_elder ONLY their own;
 *   volunteers never.
 * - hide: building_admin.
 */
final class HeatingReportPolicy
{
    use ResolvesBuildingRoles;

    public function create(User $user, Apartment $apartment): bool
    {
        if (! $this->open() || $user->isTombstone()) {
            return false;
        }

        return ApartmentMembership::query()->approved()
            ->where('user_id', $user->id)
            ->where('apartment_id', $apartment->id)
            ->exists();
    }

    public function proxy(User $user, Apartment $apartment): bool
    {
        if (! $this->open() || $user->isTombstone()) {
            return false;
        }

        return $this->isElderOf($user, (int) $apartment->section_id) || $this->isVolunteerOf($user, (int) $apartment->section_id);
    }

    public function viewApartments(User $user, Section $section): bool
    {
        return $this->isAdmin($user) || $this->isElderOf($user, (int) $section->id);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    public function view(User $user, HeatingReport $report): bool
    {
        if ($report->reported_by_user_id === $user->id) {
            return true;
        }

        $sectionId = (int) Apartment::query()->whereKey($report->apartment_id)->value('section_id');

        return $this->isAdmin($user) || $this->isElderOf($user, $sectionId);
    }

    public function hide(User $user, HeatingReport $report): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, HeatingReport $report): bool
    {
        return false;
    }

    public function delete(User $user, HeatingReport $report): bool
    {
        return false;
    }

    /**
     * Gate L-core open and the heating module enabled.
     */
    private function open(): bool
    {
        $b = $this->building();

        return $b !== null && $b->settings->moduleEnabled('heating') && CoreGate::status($b)->open;
    }

    private function isVolunteerOf(User $user, int $sectionId): bool
    {
        $b = $this->building();

        return $b !== null && $user->rolesIn($b)->contains(
            static fn (BuildingRole $r): bool => $r->role === BuildingRoleType::Volunteer && (int) $r->section_id === $sectionId,
        );
    }
}

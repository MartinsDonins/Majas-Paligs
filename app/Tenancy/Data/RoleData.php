<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

use App\Enums\BuildingRoleType;

/**
 * Cross-building view of one of the bound user's active building roles (UserScopedQuery DTO).
 */
final readonly class RoleData
{
    public function __construct(
        public int $roleId,
        public int $buildingId,
        public string $buildingSlug,
        public BuildingRoleType $role,
        public ?int $sectionId,
    ) {}

    /**
     * Roles that may open the Filament `manage` panel.
     */
    public function isManageRole(): bool
    {
        return in_array($this->role, [BuildingRoleType::BuildingAdmin, BuildingRoleType::SectionElder, BuildingRoleType::ParkingOfficer], true);
    }
}

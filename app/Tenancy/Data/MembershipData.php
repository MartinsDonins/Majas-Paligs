<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;

/**
 * Cross-building view of one of the bound user's memberships (UserScopedQuery DTO — never a Builder).
 */
final readonly class MembershipData
{
    public function __construct(
        public int $membershipId,
        public int $buildingId,
        public string $buildingSlug,
        public string $buildingName,
        public int $apartmentId,
        public int $apartmentNumber,
        public int $sectionNumber,
        public MembershipRole $role,
        public MembershipStatus $status,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\BuildingRoleType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\ApartmentMembership;
use App\Models\AuditEntry;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\User;
use App\Tenancy\Data\AuditEntryData;
use App\Tenancy\Data\MembershipData;
use App\Tenancy\Data\RoleData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * SANCTIONED cross-building query (PLANNING §4.2 item 2). Bound to ONE user; every query filters by that
 * user's id inside this class and returns DTOs/collections — never a Builder the caller could widen.
 *
 * Allowed callers (Arch test): Shell\BuildingSwitcher, Me\Privacy, Me\WhoViewed, GdprExporter, AccountEraser,
 * PinAuthService, Auth\PostLoginRedirect and App\Models\User (getTenants/canAccessTenant/getDefaultTenant).
 */
final class UserScopedQuery
{
    private function __construct(private readonly User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    /**
     * Buildings where the user has an active (pending/approved) membership or an active role.
     *
     * @return list<int>
     */
    public function buildingIds(): array
    {
        $fromMemberships = ApartmentMembership::withoutBuildingScope()
            ->where('user_id', $this->user->id)
            ->whereIn('status', [MembershipStatus::Pending->value, MembershipStatus::Approved->value])
            ->pluck('building_id');

        $fromRoles = BuildingRole::withoutBuildingScope()
            ->where('user_id', $this->user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->pluck('building_id');

        return $fromMemberships->merge($fromRoles)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, MembershipData>
     */
    public function memberships(bool $includeEnded = false): Collection
    {
        $query = ApartmentMembership::withoutBuildingScope()
            ->join('buildings', 'buildings.id', '=', 'apartment_memberships.building_id')
            ->join('apartments', function ($join): void {
                $join->on('apartments.id', '=', 'apartment_memberships.apartment_id')
                    ->on('apartments.building_id', '=', 'apartment_memberships.building_id');
            })
            ->join('sections', function ($join): void {
                $join->on('sections.id', '=', 'apartments.section_id')
                    ->on('sections.building_id', '=', 'apartments.building_id');
            })
            ->where('apartment_memberships.user_id', $this->user->id)
            ->orderBy('buildings.name')
            ->orderBy('apartments.number')
            ->select([
                'apartment_memberships.id as membership_id',
                'apartment_memberships.building_id',
                'apartment_memberships.apartment_id',
                'apartment_memberships.role',
                'apartment_memberships.status',
                'buildings.slug as building_slug',
                'buildings.name as building_name',
                'apartments.number as apartment_number',
                'sections.number as section_number',
            ]);

        if (! $includeEnded) {
            $query->whereIn('apartment_memberships.status', [MembershipStatus::Pending->value, MembershipStatus::Approved->value]);
        }

        return $query->toBase()->get()->map(static fn (object $row): MembershipData => new MembershipData(
            membershipId: (int) $row->membership_id,
            buildingId: (int) $row->building_id,
            buildingSlug: (string) $row->building_slug,
            buildingName: (string) $row->building_name,
            apartmentId: (int) $row->apartment_id,
            apartmentNumber: (int) $row->apartment_number,
            sectionNumber: (int) $row->section_number,
            role: MembershipRole::from((string) $row->role),
            status: MembershipStatus::from((string) $row->status),
        ))->values();
    }

    /**
     * Active (not revoked, not expired) building roles of the user.
     *
     * @return Collection<int, RoleData>
     */
    public function roles(): Collection
    {
        return BuildingRole::withoutBuildingScope()
            ->join('buildings', 'buildings.id', '=', 'building_roles.building_id')
            ->where('building_roles.user_id', $this->user->id)
            ->whereNull('building_roles.revoked_at')
            ->where('building_roles.expires_at', '>', now())
            ->orderBy('building_roles.building_id')
            ->select(['building_roles.id', 'building_roles.building_id', 'building_roles.role', 'building_roles.section_id', 'buildings.slug'])
            ->toBase()
            ->get()
            ->map(static fn (object $row): RoleData => new RoleData(
                roleId: (int) $row->id,
                buildingId: (int) $row->building_id,
                buildingSlug: (string) $row->slug,
                role: BuildingRoleType::from((string) $row->role),
                sectionId: $row->section_id !== null ? (int) $row->section_id : null,
            ))
            ->values();
    }

    /**
     * Buildings whose Filament `manage` panel the user may open.
     *
     * @return list<int>
     */
    public function manageBuildingIds(): array
    {
        return $this->roles()
            ->filter(static fn (RoleData $r): bool => $r->isManageRole())
            ->map(static fn (RoleData $r): int => $r->buildingId)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Audit entries where the bound user is a data subject (across buildings), newest first.
     *
     * @return Collection<int, AuditEntryData>
     */
    public function auditAboutMe(?int $beforeId = null, int $limit = 50): Collection
    {
        $query = AuditEntry::query()
            ->leftJoin('buildings', 'buildings.id', '=', 'audit_entries.building_id')
            ->leftJoin('users as actor', 'actor.id', '=', 'audit_entries.actor_user_id')
            ->whereRaw('audit_entries.subject_user_ids @> ARRAY[?]::bigint[]', [$this->user->id])
            ->orderByDesc('audit_entries.id')
            ->limit($limit)
            ->select([
                'audit_entries.id', 'audit_entries.building_id', 'audit_entries.action', 'audit_entries.actor_role',
                'audit_entries.reason_code', 'audit_entries.created_at', 'buildings.name as building_name',
                'actor.anonymised_at as actor_anonymised_at',
            ]);

        if ($beforeId !== null) {
            $query->where('audit_entries.id', '<', $beforeId);
        }

        return $query->toBase()->get()->map(static fn (object $row): AuditEntryData => new AuditEntryData(
            id: (int) $row->id,
            buildingId: $row->building_id !== null ? (int) $row->building_id : null,
            buildingName: $row->building_name !== null ? (string) $row->building_name : null,
            action: (string) $row->action,
            actorRole: $row->actor_role !== null ? (string) $row->actor_role : null,
            actorDeleted: $row->actor_anonymised_at !== null,
            reasonCode: $row->reason_code !== null ? (string) $row->reason_code : null,
            createdAt: CarbonImmutable::parse((string) $row->created_at),
        ))->values();
    }

    /**
     * Visit each of the user's buildings with CurrentBuilding set (restored afterwards). The callback runs under
     * the normal BuildingScope.
     *
     * @param  callable(Building): void  $callback
     */
    public function eachBuilding(callable $callback, bool $includeEnded = false): void
    {
        $ids = $this->buildingIds();

        if ($includeEnded) {
            $ids = array_values(array_unique(array_merge($ids, ApartmentMembership::withoutBuildingScope()
                ->where('user_id', $this->user->id)
                ->pluck('building_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all())));
            sort($ids);
        }

        foreach (Building::query()->whereIn('id', $ids)->orderBy('id')->get() as $building) {
            CurrentBuilding::runFor($building, static function (Building $b) use ($callback): void {
                $callback($b);
            });
        }
    }
}

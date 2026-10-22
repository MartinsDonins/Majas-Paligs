<?php

declare(strict_types=1);

namespace App\Platform;

use App\Audit\AuditLogger;
use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\MembershipRole;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\BuildingDocument;
use App\Models\BuildingRole;
use App\Models\Section;
use App\Models\User;
use App\Notifications\TeamMemberJoined;
use App\Services\Memberships\BuildingRoleService;
use App\Services\Memberships\SelfLinkRefused;
use App\Services\Memberships\SelfLinkService;
use App\Services\Opening\OpeningChecklist;
use App\Tenancy\BuildingIterator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

/**
 * The platform admin's first-run view of the houses (FIRSTRUN-001): "Sākt šeit" on the platform dashboard, the
 * houses without a role on the /admin chooser and the platform house page /admin/houses/{slug}.
 *
 * Reads (overview / summary) carry NO resident data: gate status, structure counts, open DPA requests and the
 * platform admin's OWN roles and membership. `overview()` visits every house through BuildingIterator (sanctioned,
 * like "Atvēršanas pieprasījumi"); a single house is read inside PlatformBuildingContext (purpose house_overview).
 *
 * Writes are only for the platform admin HIMSELF, each behind a strong step-up (passkey / TOTP of this session) and
 * inside PlatformBuildingContext (platform chain) + the house chain:
 * - claimAdmin: "Kļūt par šīs mājas administratoru" (purpose role_bootstrap → BuildingRoleService::grant, `role.grant`);
 * - linkSelf: "Piesaistīt mani dzīvoklim" (purpose self_link → SelfLinkService, approved, `membership.self_link`).
 */
final class PlatformHouses
{
    public function __construct(private readonly StepUpService $stepUp) {}

    /**
     * Every non-archived house, with the actor's own role / membership in it.
     *
     * @return list<array<string, mixed>>
     */
    public static function overview(User $actor): array
    {
        $rows = [];
        BuildingIterator::each(static function (Building $building) use (&$rows, $actor): void {
            $rows[] = self::summary($building, $actor);
        });

        usort($rows, static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /**
     * One house as the first-run screens show it. The building context of $building must be set.
     *
     * @return array<string, mixed>
     */
    public static function summary(Building $building, User $actor): array
    {
        $checklist = OpeningChecklist::for($building);
        $cards = $checklist->gateCards();
        $roles = $actor->rolesIn($building);
        $membership = $actor->membershipFor($building);
        $number = $membership !== null ? (int) Apartment::query()->whereKey($membership->apartment_id)->value('number') : null;

        $roleLabels = [];
        foreach (BuildingRoleType::cases() as $type) {
            if ($roles->contains(static fn (BuildingRole $r): bool => $r->role === $type)) {
                $roleLabels[] = $type->label();
            }
        }

        return [
            'id' => (int) $building->id,
            'slug' => (string) $building->slug,
            'name' => (string) $building->name,
            'address' => trim($building->address_line.', '.$building->city, ', '),
            'status' => $building->status->value,
            'status_label' => $building->status->label(),
            'sections' => Section::query()->count(),
            'apartments' => Apartment::query()->active()->count(),
            'core_open' => $checklist->core->open,
            'core_progress' => $checklist->progress('core'),
            'core_missing' => $cards['core']['missing'],
            'roster_open' => $checklist->roster->open,
            'parking_chip' => $cards['parking']['chip'],
            'heating_on' => $building->settings->moduleEnabled('heating'),
            'outages_on' => $building->settings->moduleEnabled('outages'),
            'roles' => $roleLabels,
            'is_admin' => $roles->contains(static fn (BuildingRole $r): bool => $r->role === BuildingRoleType::BuildingAdmin),
            'can_manage' => $roles->contains(static fn (BuildingRole $r): bool => in_array($r->role, [BuildingRoleType::BuildingAdmin, BuildingRoleType::SectionElder, BuildingRoleType::ParkingOfficer], true)),
            'admins' => BuildingRole::query()->current()->where('role', BuildingRoleType::BuildingAdmin->value)->distinct()->count('user_id'),
            'membership' => $membership !== null ? ['number' => $number, 'status' => $membership->status->value] : null,
            'pending_requests' => BuildingDocument::query()
                ->whereIn('kind', [DocumentKind::Dpa->value, DocumentKind::DpaAnnex->value])
                ->where('status', DocumentStatus::Sent->value)
                ->count(),
        ];
    }

    /**
     * The house page read: platform_admin + fresh MFA, audited on the platform chain (purpose house_overview).
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public static function read(Building $building, User $actor): array
    {
        return PlatformBuildingContext::run($building, PlatformPurpose::HouseOverview, static fn (Building $b): array => self::summary($b, $actor));
    }

    /**
     * "Kļūt par šīs mājas administratoru" — the platform admin grants HIMSELF building_admin (12 months, mandate ref).
     * Other administrators of the house are told (count-free text), so a house with its own team sees it at once.
     *
     * @throws AuthorizationException|SelfLinkRefused
     */
    public function claimAdmin(User $actor, Building $building, ?string $mandateRef): BuildingRole
    {
        $this->assertStrong($actor);
        $mandateRef = $mandateRef !== null && trim($mandateRef) !== '' ? mb_substr(trim($mandateRef), 0, 200) : null;

        return PlatformBuildingContext::run($building, PlatformPurpose::RoleBootstrap, function (Building $b) use ($actor, $mandateRef): BuildingRole {
            if ($actor->isBuildingAdmin($b)) {
                throw new SelfLinkRefused('already_admin');
            }

            $others = BuildingRole::query()->current()->where('role', BuildingRoleType::BuildingAdmin->value)->pluck('user_id');
            $role = app(BuildingRoleService::class)->grant($actor, $actor, BuildingRoleType::BuildingAdmin, null, $mandateRef);

            AuditLogger::record(
                action: 'platform.self_admin',
                subject: $b,
                subjectUserIds: [(int) $actor->id],
                data: ['building_id' => (int) $b->id, 'role_id' => (int) $role->id],
                platform: true,
                actorRole: 'platform_admin',
                actorUserId: (int) $actor->id,
            );

            $recipients = User::query()->whereIn('id', $others)->where('id', '!=', $actor->id)->whereNull('anonymised_at')->whereNotNull('email')->get();
            Notification::send($recipients, new TeamMemberJoined($b->name, BuildingRoleType::BuildingAdmin->value));

            return $role;
        });
    }

    /**
     * "Piesaistīt mani dzīvoklim" — approved at once for the platform admin himself (documented exception, see
     * SelfLinkService). Below gate L-core the house cannot hold memberships at all (refused `house_closed`).
     *
     * @throws AuthorizationException
     */
    public function linkSelf(User $actor, Building $building, int $apartmentNumber, MembershipRole $role): ApartmentMembership
    {
        $this->assertStrong($actor);

        return PlatformBuildingContext::run($building, PlatformPurpose::SelfLink, function (Building $b) use ($actor, $apartmentNumber, $role): ApartmentMembership {
            $membership = app(SelfLinkService::class)->link($actor, $apartmentNumber, $role, selfApprove: true);

            AuditLogger::record(
                action: 'platform.self_link',
                subject: $b,
                subjectUserIds: [(int) $actor->id],
                data: ['building_id' => (int) $b->id, 'membership_id' => (int) $membership->id],
                platform: true,
                actorRole: 'platform_admin',
                actorUserId: (int) $actor->id,
            );

            return $membership;
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function assertStrong(User $actor): void
    {
        if (! $actor->isPlatformAdmin()) {
            throw new AuthorizationException('Only a platform_admin uses the platform house actions.');
        }

        $interactive = ! app()->runningInConsole() || app()->runningUnitTests();
        if ($interactive && ! $this->stepUp->hasFreshStrongMfa($actor)) {
            throw new AuthorizationException('A passkey or TOTP of this session is required.');
        }
    }
}

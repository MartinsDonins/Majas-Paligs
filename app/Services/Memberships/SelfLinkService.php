<?php

declare(strict_types=1);

namespace App\Services\Memberships;

use App\Audit\AuditLogger;
use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Gdpr\CoreGate;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\BuildingPrivacyNotice;
use App\Models\BuildingRole;
use App\Models\User;
use App\Notifications\MembershipRequested;
use App\Services\Roster\RosterLinker;
use App\Tenancy\CurrentBuilding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * "Piesaistīt mani dzīvoklim" (FIRSTRUN-001): an administrator links HIMSELF to an apartment of the CURRENT building
 * without an invite letter. Only for oneself, never for somebody else.
 *
 * - building_admin (not platform): a fresh second factor; the membership is PENDING — the rule "never self-approve"
 *   holds, another administrator or the stairwell elder approves it (the page offers "Uzaicināt vecāko").
 * - platform_admin (called through PlatformHouses::linkSelf → PlatformBuildingContext, purpose self_link): a STRONG
 *   step-up (passkey / TOTP of this session); the membership is APPROVED at once and marked self-approved
 *   (approved_by = the member). Documented exception in docs/SECURITY.md: a house's first administrator is often the
 *   only one, so nobody else could approve him.
 *
 * Gate L-core still applies (model guard on ApartmentMembership): below it the house holds no resident data at all.
 * Audit: `membership.self_link` on the building chain (subject = the actor) — the platform path adds the
 * platform-chain entry. Existing approved members of that apartment and its stairwell elder(s) are notified.
 */
final class SelfLinkService
{
    public function __construct(private readonly StepUpService $stepUp) {}

    /**
     * @throws AuthorizationException|SelfLinkRefused
     */
    public function link(User $actor, int $apartmentNumber, MembershipRole $role, bool $selfApprove = false): ApartmentMembership
    {
        $building = CurrentBuilding::get();
        $interactive = ! app()->runningInConsole() || app()->runningUnitTests();

        if ($actor->isTombstone()) {
            throw new AuthorizationException('Deleted accounts cannot be linked.');
        }

        if ($selfApprove) {
            if (! $actor->isPlatformAdmin()) {
                throw new AuthorizationException('Only a platform_admin may approve his own membership.');
            }
            if ($interactive && ! $this->stepUp->hasFreshStrongMfa($actor)) {
                throw new AuthorizationException('Self-approval needs a passkey or TOTP of this session.');
            }
        } else {
            if (! $actor->isBuildingAdmin($building) && ! $actor->isPlatformAdmin()) {
                throw new AuthorizationException('Only an administrator links himself without a letter.');
            }
            if ($interactive && ! $this->stepUp->hasFreshMfa($actor)) {
                throw new AuthorizationException('Linking yourself needs a fresh second factor.');
            }
        }

        if (! CoreGate::status($building)->open) {
            throw new SelfLinkRefused('house_closed');
        }

        if ($actor->membershipFor($building) !== null) {
            throw new SelfLinkRefused('already_member');
        }

        /** @var Apartment|null $apartment */
        $apartment = Apartment::query()->active()->where('number', $apartmentNumber)->first();
        if ($apartment === null) {
            throw new SelfLinkRefused('apartment_not_found');
        }

        $membership = DB::transaction(function () use ($actor, $apartment, $role, $selfApprove): ApartmentMembership {
            $membership = ApartmentMembership::query()->create([
                'apartment_id' => $apartment->id,
                'user_id' => $actor->id,
                'role' => $role,
                'status' => $selfApprove ? MembershipStatus::Approved : MembershipStatus::Pending,
                'approved_by' => $selfApprove ? $actor->id : null,
                'approved_at' => $selfApprove ? now() : null,
                'reconfirm_due_at' => $selfApprove ? now()->addMonths(12) : null,
                'building_notice_id' => BuildingPrivacyNotice::current()?->id,
            ]);

            AuditLogger::record(
                action: 'membership.self_link',
                subject: $membership,
                subjectUserIds: [(int) $actor->id],
                reasonCode: $selfApprove ? 'self_approved' : 'self_request',
                data: ['apartment_id' => (int) $apartment->id, 'role' => $role->value, 'approved' => $selfApprove],
                actorRole: $selfApprove ? 'platform_admin' : 'building_admin',
                actorUserId: (int) $actor->id,
            );

            return $membership;
        });

        if ($selfApprove) {
            app(RosterLinker::class)->afterMembershipApproved($membership);
        }

        $this->notify($membership, $apartment, $actor);

        return $membership;
    }

    /**
     * True when the membership was approved by its own member (only the platform self-link does that) — shown as
     * "Pašapstiprināts" in Dalības and on the Komanda page.
     */
    public static function isSelfApproved(ApartmentMembership $membership): bool
    {
        return $membership->isApproved() && $membership->approved_by !== null && (int) $membership->approved_by === (int) $membership->user_id;
    }

    private function notify(ApartmentMembership $membership, Apartment $apartment, User $actor): void
    {
        $building = CurrentBuilding::get();
        $role = $membership->role->value;

        $memberIds = ApartmentMembership::query()->approved()
            ->where('apartment_id', $apartment->id)
            ->where('user_id', '!=', $actor->id)
            ->pluck('user_id');
        $members = User::query()->whereIn('id', $memberIds)->whereNull('anonymised_at')->get();
        Notification::send($members, new MembershipRequested($building->name, $apartment->number, $role, forElder: false));

        if ($membership->isPending()) {
            $elderIds = BuildingRole::query()->current()
                ->where('role', BuildingRoleType::SectionElder->value)
                ->where('section_id', $apartment->section_id)
                ->pluck('user_id');
            $elders = User::query()->whereIn('id', $elderIds)->where('id', '!=', $actor->id)->whereNull('anonymised_at')->get();
            Notification::send($elders, new MembershipRequested($building->name, $apartment->number, $role, forElder: true));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Audit\AuditLogger;
use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\ApartmentMembership;
use App\Models\BuildingRole;
use App\Models\User;
use App\Notifications\MembershipRequested;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\HouseClosed;
use App\Tenancy\InviteResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Redeems a resolved invite for a user: re-enters the invite's building via InviteResolver::resume (gate L-core
 * re-checked), consumes one use atomically, creates a PENDING membership (with the building notice shown at
 * redemption), notifies the stairwell elder(s) and the apartment's approved members, and audits.
 */
final class JoinCompleter
{
    public function __construct(private readonly InviteResolver $resolver) {}

    /**
     * @return string|null the building slug on success
     */
    public function complete(User $user, JoinState $state): ?string
    {
        if ($state->role === null) {
            return null;
        }

        try {
            $resolved = $this->resolver->resume($state->invite);
        } catch (HouseClosed) {
            return null;
        }

        if ($resolved === null) {
            return null;
        }

        $building = CurrentBuilding::get();

        $existing = ApartmentMembership::query()
            ->where('apartment_id', $resolved->apartmentId)
            ->where('user_id', $user->id)
            ->active()
            ->first();

        if ($existing !== null) {
            return $building->slug;
        }

        $membership = DB::transaction(function () use ($user, $state, $resolved): ?ApartmentMembership {
            $claimed = ApartmentInvite::query()
                ->whereKey($resolved->inviteId)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->whereColumn('uses', '<', 'max_uses')
                ->increment('uses');

            if ($claimed !== 1) {
                return null;
            }

            $membership = ApartmentMembership::query()->create([
                'apartment_id' => $resolved->apartmentId,
                'user_id' => $user->id,
                'role' => $state->role,
                'status' => MembershipStatus::Pending,
                'invite_id' => $resolved->inviteId,
                'building_notice_id' => $resolved->buildingNoticeId,
            ]);

            $user->forceFill([
                'is_adult_confirmed_at' => $user->is_adult_confirmed_at ?? ($state->adultConfirmed ? now() : null),
                'notice_version_accepted' => $state->platformNoticeVersion ?? $user->notice_version_accepted,
            ])->save();

            AuditLogger::record(
                action: 'membership.request',
                subject: $membership,
                subjectUserIds: [(int) $user->id],
                data: ['apartment_id' => $resolved->apartmentId, 'role' => $state->role?->value, 'building_notice_id' => $resolved->buildingNoticeId],
                actorUserId: (int) $user->id,
                actorRole: 'resident',
            );

            return $membership;
        });

        if ($membership === null) {
            return null;
        }

        $this->notify($membership, $user);

        return $building->slug;
    }

    private function notify(ApartmentMembership $membership, User $requester): void
    {
        $building = CurrentBuilding::get();
        /** @var Apartment $apartment */
        $apartment = Apartment::query()->whereKey($membership->apartment_id)->firstOrFail();

        $elderIds = BuildingRole::query()->current()
            ->where('role', BuildingRoleType::SectionElder->value)
            ->where('section_id', $apartment->section_id)
            ->pluck('user_id');

        $memberIds = ApartmentMembership::query()->approved()
            ->where('apartment_id', $apartment->id)
            ->where('user_id', '!=', $requester->id)
            ->pluck('user_id');

        $role = $membership->role->value;

        $elders = User::query()->whereIn('id', $elderIds)->where('id', '!=', $requester->id)->whereNull('anonymised_at')->get();
        Notification::send($elders, new MembershipRequested($building->name, $apartment->number, $role, forElder: true));

        $members = User::query()->whereIn('id', $memberIds)->whereNull('anonymised_at')->get();
        Notification::send($members, new MembershipRequested($building->name, $apartment->number, $role, forElder: false));
    }
}

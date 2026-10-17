<?php

declare(strict_types=1);

namespace App\Services\Memberships;

use App\Audit\AuditLogger;
use App\Enums\MembershipStatus;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Notifications\MembershipDecided;
use App\Tenancy\CurrentBuilding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Approve / reject / end memberships (resident Elder panel and Filament MembershipResource share this).
 * Authorisation via ApartmentMembershipPolicy (elder of THAT section or building_admin, never self).
 */
final class MembershipService
{
    /**
     * @throws AuthorizationException
     */
    public function approve(User $actor, ApartmentMembership $membership): void
    {
        Gate::forUser($actor)->authorize('approve', $membership);

        DB::transaction(function () use ($actor, $membership): void {
            $membership->forceFill([
                'status' => MembershipStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'reconfirm_due_at' => now()->addMonths(12),
            ])->save();

            AuditLogger::record('membership.approve', $membership, [(int) $membership->user_id], actorRole: $this->actorRole($actor, $membership), actorUserId: (int) $actor->id);
        });

        $this->notifyRequester($membership, approved: true);
    }

    /**
     * @throws AuthorizationException
     */
    public function reject(User $actor, ApartmentMembership $membership, ?string $reason = null): void
    {
        Gate::forUser($actor)->authorize('reject', $membership);

        DB::transaction(function () use ($actor, $membership, $reason): void {
            $membership->forceFill([
                'status' => MembershipStatus::Rejected,
                'approved_by' => $actor->id,
                'rejected_reason' => $reason !== null ? mb_substr($reason, 0, 200) : null,
            ])->save();

            AuditLogger::record('membership.reject', $membership, [(int) $membership->user_id], reasonCode: 'elder_decision', actorRole: $this->actorRole($actor, $membership), actorUserId: (int) $actor->id);
        });

        $this->notifyRequester($membership, approved: false);
    }

    /**
     * @throws AuthorizationException
     */
    public function end(User $actor, ApartmentMembership $membership, string $reasonCode = 'ended'): void
    {
        Gate::forUser($actor)->authorize('end', $membership);

        $membership->forceFill(['status' => MembershipStatus::Ended, 'ended_at' => now()])->save();
        AuditLogger::record('membership.end', $membership, [(int) $membership->user_id], reasonCode: $reasonCode, actorRole: $this->actorRole($actor, $membership), actorUserId: (int) $actor->id);
    }

    private function actorRole(User $actor, ApartmentMembership $membership): string
    {
        $building = CurrentBuilding::get();

        if ($actor->id === $membership->user_id) {
            return 'self';
        }

        return $actor->isBuildingAdmin($building) ? 'building_admin' : 'section_elder';
    }

    private function notifyRequester(ApartmentMembership $membership, bool $approved): void
    {
        $user = User::query()->find($membership->user_id);
        $number = (int) Apartment::query()->whereKey($membership->apartment_id)->value('number');

        $user?->notify(new MembershipDecided(CurrentBuilding::get()->name, $number, $approved));
    }
}

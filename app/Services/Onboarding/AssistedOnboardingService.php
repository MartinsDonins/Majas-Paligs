<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Audit\AuditLogger;
use App\Auth\LoginHandleGenerator;
use App\Auth\PinAuthService;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\PrivacyNoticeKind;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\ApartmentMembership;
use App\Models\PrivacyNoticeVersion;
use App\Models\User;
use App\Tenancy\ResolvedInvite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Elder-assisted onboarding ("Tas esmu es", UI-005): the elder hands the phone to a neighbour who holds the
 * mailbox letter. The neighbour confirms 18+ and the notices and sets a PIN; the elder of THAT section vouches
 * in person, so the membership is approved at once. Audited with the neighbour as data subject.
 */
final class AssistedOnboardingService
{
    public function __construct(
        private readonly PinAuthService $pins,
        private readonly LoginHandleGenerator $handles,
    ) {}

    /**
     * @return User the new PIN account (login_handle filled)
     *
     * @throws AuthorizationException
     */
    public function create(User $elder, ResolvedInvite $invite, MembershipRole $role, #[\SensitiveParameter] string $pin): User
    {
        /** @var Apartment $apartment */
        $apartment = Apartment::query()->whereKey($invite->apartmentId)->firstOrFail();
        $building = $apartment->building()->firstOrFail();

        $allowed = $elder->isBuildingAdmin($building) || in_array($apartment->section_id, $elder->elderSectionIds($building), true);
        if (! $allowed) {
            throw new AuthorizationException('Assisted onboarding only for the elder\'s own stairwell.');
        }

        return DB::transaction(function () use ($elder, $invite, $role, $pin, $apartment): User {
            $claimed = ApartmentInvite::query()->whereKey($invite->inviteId)
                ->whereNull('revoked_at')->where('expires_at', '>', now())
                ->whereColumn('uses', '<', 'max_uses')
                ->increment('uses');

            if ($claimed !== 1) {
                throw new RuntimeException('Invite no longer usable.');
            }

            $user = User::query()->create(['locale' => app()->getLocale()]);
            $user->forceFill([
                'login_handle' => $this->handles->generate($apartment->number),
                'is_adult_confirmed_at' => now(),
                'notice_version_accepted' => PrivacyNoticeVersion::current(PrivacyNoticeKind::PlatformAccount)?->version,
            ])->save();
            $this->pins->setPin($user, $pin, $apartment->number);

            $membership = ApartmentMembership::query()->create([
                'apartment_id' => $apartment->id,
                'user_id' => $user->id,
                'role' => $role,
                'status' => MembershipStatus::Approved,
                'invite_id' => $invite->inviteId,
                'approved_by' => $elder->id,
                'approved_at' => now(),
                'reconfirm_due_at' => now()->addMonths(12),
                'building_notice_id' => $invite->buildingNoticeId,
            ]);

            AuditLogger::record(
                action: 'membership.assisted',
                subject: $membership,
                subjectUserIds: [(int) $user->id],
                data: ['apartment_id' => $apartment->id, 'role' => $role->value],
                actorRole: 'section_elder',
                actorUserId: (int) $elder->id,
            );

            return $user;
        });
    }
}

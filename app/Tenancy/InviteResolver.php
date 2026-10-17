<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Gdpr\CoreGate;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\Building;
use App\Models\Section;
use App\Services\Invites\InviteCodeService;
use App\Tenancy\Exceptions\HouseClosed;

/**
 * SANCTIONED cross-building lookup for /join (PLANNING §4.2 item 1, MVP-SPEC A.5).
 *
 * normalize → HMAC → apartment_invites by the global UNIQUE(code_hmac) without building scope → validity
 * (not revoked, not expired, uses < max, apartment not archived/absorbed) → CoreGate (closed → HouseClosed,
 * no context set) → CurrentBuilding::set() → DTO. Unknown / revoked / expired / used-up codes all return null.
 * The `invite` throttle runs BEFORE this class. The typed code is never logged.
 */
final class InviteResolver
{
    public function __construct(private readonly InviteCodeService $codes) {}

    /**
     * @throws HouseClosed when the code is valid but the building's L-core gate is closed
     */
    public function resolve(#[\SensitiveParameter] string $typed): ?ResolvedInvite
    {
        $normalized = $this->codes->tryNormalize($typed);

        if ($normalized === null) {
            return null;
        }

        $invite = ApartmentInvite::withoutBuildingScope()
            ->where('code_hmac', $this->codes->hmac($normalized))
            ->first();

        return $invite === null ? null : $this->validateAndEnter($invite);
    }

    /**
     * Re-enter the context of a previously resolved invite (next /join step, a new request).
     *
     * @throws HouseClosed
     */
    public function resume(ResolvedInvite $state): ?ResolvedInvite
    {
        $invite = ApartmentInvite::withoutBuildingScope()
            ->whereKey($state->inviteId)
            ->where('building_id', $state->buildingId)
            ->where('apartment_id', $state->apartmentId)
            ->first();

        return $invite === null ? null : $this->validateAndEnter($invite);
    }

    private function validateAndEnter(ApartmentInvite $invite): ?ResolvedInvite
    {
        if (! $invite->isUsable()) {
            return null;
        }

        $building = Building::query()->find($invite->building_id);

        if ($building === null) {
            return null;
        }

        // Read the apartment/section and the gate inside a temporary context (restored afterwards), so a
        // closed gate leaves NO context behind.
        $resolved = CurrentBuilding::runFor($building, static function (Building $b) use ($invite): ?ResolvedInvite {
            /** @var Apartment|null $apartment */
            $apartment = Apartment::query()->whereKey($invite->apartment_id)->first();

            if ($apartment === null || $apartment->isArchived()) {
                return null;
            }

            $status = CoreGate::status($b);

            if (! $status->open || $status->noticeId === null) {
                throw new HouseClosed;
            }

            /** @var Section $section */
            $section = Section::query()->whereKey($apartment->section_id)->firstOrFail();

            return new ResolvedInvite(
                inviteId: (string) $invite->id,
                buildingId: (int) $b->id,
                apartmentId: (int) $apartment->id,
                apartmentNumber: $apartment->number,
                sectionNumber: $section->number,
                floor: $apartment->floor,
                buildingNoticeId: $status->noticeId,
            );
        });

        if ($resolved !== null) {
            CurrentBuilding::set($building);
        }

        return $resolved;
    }
}

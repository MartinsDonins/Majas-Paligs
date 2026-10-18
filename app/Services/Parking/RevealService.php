<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Audit\AuditLogger;
use App\Enums\BuildingRoleType;
use App\Enums\RevealReason;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\AuditEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\VehicleRevealedNotice;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\Notification;

/**
 * Reveal plate ↔ apartment (PARK-002.1, MVP-SPEC C.5): parking_officer / building_admin only (VehiclePolicy::reveal
 * — includes a fresh step-up), reason enum + text (required for "other"), cap 10 per user per day, audit-logged with
 * subject_user_ids = the approved members of the apartment (so it shows in their "Kas skatīja manus datus"), and an
 * e-mail notice to them. A search that finds nothing still counts towards the cap and is audited.
 */
final class RevealService
{
    public const DAILY_CAP = 10;

    public const ACTIONS = ['vehicle.reveal', 'vehicle.reveal_search'];

    public function __construct(
        private readonly VehicleRegistry $registry,
        private readonly PlateVault $vault,
    ) {}

    /**
     * Full plate + apartment of a listed vehicle.
     *
     * @return array{plate: string, apartment: int}
     *
     * @throws ParkingActionFailed reason_text | cap
     */
    public function revealVehicle(User $officer, Vehicle $vehicle, RevealReason $reason, ?string $text = null): array
    {
        $text = $this->checkReason($officer, $reason, $text);
        $subjects = $this->subjects((int) $vehicle->apartment_id);

        AuditLogger::record('vehicle.reveal', $vehicle, $subjects, reasonCode: $reason->value, reasonText: $text, actorRole: $this->role($officer));
        $this->notify($subjects, $reason);

        return [
            'plate' => $this->registry->plateOf($vehicle),
            'apartment' => (int) Apartment::query()->whereKey($vehicle->apartment_id)->value('number'),
        ];
    }

    /**
     * "Atrast pēc numura": which apartment an ACTIVE plate belongs to (null = not in the registry).
     *
     * @return array{apartment: int, vehicle_id: string}|null
     *
     * @throws ParkingActionFailed invalid_plate | reason_text | cap
     */
    public function findByPlate(User $officer, string $typedPlate, RevealReason $reason, ?string $text = null): ?array
    {
        $plate = PlateNormalizer::normalize($typedPlate) ?? throw new ParkingActionFailed('invalid_plate');
        $text = $this->checkReason($officer, $reason, $text);

        $vehicle = Vehicle::query()->active()->withBidx($this->vault->currentBidx(CurrentBuilding::get(), $plate))->first();
        $subjects = $vehicle !== null ? $this->subjects((int) $vehicle->apartment_id) : [];

        AuditLogger::record('vehicle.reveal_search', $vehicle ?? ['vehicle', 'none'], $subjects, reasonCode: $reason->value, reasonText: $text, data: ['found' => $vehicle !== null], actorRole: $this->role($officer));

        if ($vehicle === null) {
            return null;
        }

        $this->notify($subjects, $reason);

        return [
            'apartment' => (int) Apartment::query()->whereKey($vehicle->apartment_id)->value('number'),
            'vehicle_id' => (string) $vehicle->id,
        ];
    }

    public function usedToday(User $officer): int
    {
        return AuditEntry::query()
            ->whereIn('action', self::ACTIONS)
            ->where('actor_user_id', $officer->id)
            ->where('created_at', '>', now()->subDay())
            ->count();
    }

    /**
     * The actor's ROLE for "Kas skatīja manus datus" (never a name).
     */
    private function role(User $officer): string
    {
        return $officer->isBuildingAdmin(CurrentBuilding::get()) ? BuildingRoleType::BuildingAdmin->value : BuildingRoleType::ParkingOfficer->value;
    }

    private function checkReason(User $officer, RevealReason $reason, ?string $text): ?string
    {
        $text = $text !== null && trim($text) !== '' ? mb_substr(trim($text), 0, 300) : null;

        if ($reason === RevealReason::Other && $text === null) {
            throw new ParkingActionFailed('reason_text');
        }

        if ($this->usedToday($officer) >= self::DAILY_CAP) {
            throw new ParkingActionFailed('cap');
        }

        return $text;
    }

    /**
     * @return list<int>
     */
    private function subjects(int $apartmentId): array
    {
        return ApartmentMembership::query()->approved()->where('apartment_id', $apartmentId)
            ->pluck('user_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * @param  list<int>  $userIds
     */
    private function notify(array $userIds, RevealReason $reason): void
    {
        if ($userIds === []) {
            return;
        }

        $users = User::query()->whereIn('id', $userIds)->whereNull('anonymised_at')->get();
        Notification::send($users, new VehicleRevealedNotice(CurrentBuilding::get()->name, $reason->value));
    }
}

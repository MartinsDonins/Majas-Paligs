<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Audit\AuditLogger;
use App\Enums\LookupReason;
use App\Enums\OfficerTaskKind;
use App\Enums\OfficerTaskStatus;
use App\Enums\PermitStatus;
use App\Enums\PermitType;
use App\Enums\VehicleSource;
use App\Enums\VehicleStatus;
use App\Models\Apartment;
use App\Models\ApartmentParkingDeclaration;
use App\Models\ParkingOfficerTask;
use App\Models\Permit;
use App\Models\User;
use App\Models\Vehicle;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vehicle registry (PARK-001 / PARK-006, MVP-SPEC C.5):
 *
 * - Resident declarations ("Pievienot auto", DeclarationWizard, ChangeVehicleWizard) seal the plate and insert the
 *   vehicle as `pending` WITHOUT touching the partial UNIQUE (it covers `active` only). If the plate is active in
 *   another apartment the row gets `conflict = true` and a vehicle_conflict officer task — the resident always gets
 *   the identical "gaida apstiprinājumu" answer. Every declared plate writes a plate_lookups row (declaration) and
 *   consumes one unit of the shared lookup quota.
 * - Only an officer/admin action sets `active`; activation re-checks uniqueness inside the transaction. A pending
 *   plate change (task with permit_id) replaces the bound main permit (history kept via replaced_by_permit_id).
 *
 * Authorisation happens in the callers (VehiclePolicy / DeclarationPolicy); model guards enforce gate L1.
 */
final class VehicleRegistry
{
    public const RECONFIRM_MONTHS = 12;

    public function __construct(
        private readonly PlateVault $vault,
        private readonly LookupQuota $quota,
        private readonly PermitService $permits,
    ) {}

    public function declare(
        User $user,
        Apartment $apartment,
        string $typedPlate,
        ?string $country = null,
        bool $isCompanyCar = false,
        ?Permit $replacing = null,
    ): DeclarationResult {
        $plate = PlateNormalizer::normalize($typedPlate);
        if ($plate === null) {
            return new DeclarationResult(DeclarationResult::INVALID);
        }

        $building = CurrentBuilding::get();
        $bidx = $this->vault->currentBidx($building, $plate);
        $activeElsewhere = Vehicle::query()->active()->withBidx($bidx)->where('apartment_id', '!=', $apartment->id)->exists();

        $retry = $this->quota->record($user, $bidx, LookupReason::Declaration, $activeElsewhere);
        if ($retry !== null) {
            return new DeclarationResult(DeclarationResult::RATE_LIMITED, $retry);
        }

        DB::transaction(function () use ($user, $apartment, $plate, $bidx, $country, $isCompanyCar, $replacing, $activeElsewhere, $building): void {
            // Same plate already pending/active for THIS apartment → nothing new to store (same answer either way).
            $existing = Vehicle::query()->current()->withBidx($bidx)->where('apartment_id', $apartment->id)->first();

            if ($existing === null) {
                $id = strtolower((string) Str::ulid());
                $sealed = $this->vault->seal($plate, new VaultContext((int) $building->id, 'vehicles', $id), $building->settings->plateIndexKeyVersion);

                $existing = Vehicle::query()->create([
                    'id' => $id,
                    'apartment_id' => $apartment->id,
                    'plate_ciphertext' => $sealed->ciphertext,
                    'plate_bidx' => $sealed->bidx,
                    'plate_key_version' => $sealed->keyVersion,
                    'country' => PlateNormalizer::country($country),
                    'is_company_car' => $isCompanyCar,
                    'status' => VehicleStatus::Pending,
                    'conflict' => $activeElsewhere,
                    'source' => VehicleSource::SelfDeclared,
                    'declared_by_user_id' => $user->id,
                ]);
            }

            if ($existing->status === VehicleStatus::Pending
                && ($replacing !== null || ! ParkingOfficerTask::query()->open()->where('vehicle_id', $existing->id)->exists())) {
                ParkingOfficerTask::query()->create([
                    'kind' => $existing->conflict ? OfficerTaskKind::VehicleConflict : OfficerTaskKind::VehicleVerify,
                    'vehicle_id' => $existing->id,
                    'permit_id' => $replacing?->id,
                ]);
            }

            $this->markDeclared($user, $apartment, hasNoVehicle: false);

            AuditLogger::record('vehicle.declare', $existing, [(int) $user->id], data: ['change' => $replacing !== null]);
        });

        return new DeclarationResult(DeclarationResult::PENDING);
    }

    /**
     * "Mums nav auto". Refused (false) while the apartment still has pending/active vehicles.
     */
    public function declareNoVehicle(User $user, Apartment $apartment): bool
    {
        if (Vehicle::query()->current()->where('apartment_id', $apartment->id)->exists()) {
            return false;
        }

        $this->markDeclared($user, $apartment, hasNoVehicle: true);
        AuditLogger::record('parking.declare_no_vehicle', $apartment, [(int) $user->id]);
        Completeness::forget();

        return true;
    }

    /**
     * Officer/admin: verify a pending vehicle → active (uniqueness re-checked under lock). Completes its open tasks
     * and, for a plate change, replaces the bound main permit.
     *
     * @throws ParkingActionFailed conflict
     */
    public function activate(User $officer, Vehicle $vehicle, ?string $replacementNumber = null): Vehicle
    {
        $vehicle = DB::transaction(function () use ($officer, $vehicle, $replacementNumber): Vehicle {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['plates:'.$vehicle->building_id]);
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);

            if (Vehicle::query()->active()->withBidx($vehicle->plate_bidx)->whereKeyNot($vehicle->id)->exists()) {
                throw new ParkingActionFailed('conflict');
            }

            $vehicle->forceFill([
                'status' => VehicleStatus::Active,
                'conflict' => false,
                'verified_at' => now(),
                'verified_by' => $officer->id,
                'reconfirm_due_at' => now()->addMonths(self::RECONFIRM_MONTHS),
            ])->save();

            $tasks = ParkingOfficerTask::query()->open()->where('vehicle_id', $vehicle->id)->get();
            foreach ($tasks as $task) {
                $permit = $task->permit_id !== null ? Permit::query()->find($task->permit_id) : null;
                if ($permit !== null && $permit->type === PermitType::Main && $permit->status === PermitStatus::Active
                    && (int) $permit->apartment_id === (int) $vehicle->apartment_id) {
                    $this->permits->replace($officer, $permit, $vehicle, $replacementNumber);
                }
                $task->forceFill(['status' => OfficerTaskStatus::Done, 'done_by' => $officer->id, 'done_at' => now()])->save();
            }

            AuditLogger::record('vehicle.activate', $vehicle, $vehicle->declared_by_user_id !== null ? [(int) $vehicle->declared_by_user_id] : []);

            return $vehicle;
        });

        Completeness::forget();

        return $vehicle;
    }

    public function reject(User $officer, Vehicle $vehicle): void
    {
        DB::transaction(function () use ($officer, $vehicle): void {
            $vehicle->forceFill(['status' => VehicleStatus::Rejected, 'conflict' => false])->save();
            ParkingOfficerTask::query()->open()->where('vehicle_id', $vehicle->id)
                ->update(['status' => OfficerTaskStatus::Done->value, 'done_by' => $officer->id, 'done_at' => now()]);
            AuditLogger::record('vehicle.reject', $vehicle, $vehicle->declared_by_user_id !== null ? [(int) $vehicle->declared_by_user_id] : []);
        });
    }

    /**
     * Officer/admin adds a vehicle directly (e.g. from the permit issuer's list) — active immediately.
     *
     * @throws ParkingActionFailed invalid_plate | conflict
     */
    public function officerCreate(User $officer, Apartment $apartment, string $typedPlate, ?string $country = null, bool $isCompanyCar = false, VehicleSource $source = VehicleSource::Officer): Vehicle
    {
        $plate = PlateNormalizer::normalize($typedPlate) ?? throw new ParkingActionFailed('invalid_plate');
        $building = CurrentBuilding::get();

        $vehicle = DB::transaction(function () use ($officer, $apartment, $plate, $country, $isCompanyCar, $source, $building): Vehicle {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['plates:'.$building->id]);
            $bidx = $this->vault->currentBidx($building, $plate);

            if (Vehicle::query()->active()->withBidx($bidx)->exists()) {
                throw new ParkingActionFailed('conflict');
            }

            $id = strtolower((string) Str::ulid());
            $sealed = $this->vault->seal($plate, new VaultContext((int) $building->id, 'vehicles', $id), $building->settings->plateIndexKeyVersion);

            $vehicle = Vehicle::query()->create([
                'id' => $id,
                'apartment_id' => $apartment->id,
                'plate_ciphertext' => $sealed->ciphertext,
                'plate_bidx' => $sealed->bidx,
                'plate_key_version' => $sealed->keyVersion,
                'country' => PlateNormalizer::country($country),
                'is_company_car' => $isCompanyCar,
                'status' => VehicleStatus::Active,
                'source' => $source,
                'verified_at' => now(),
                'verified_by' => $officer->id,
                'reconfirm_due_at' => now()->addMonths(self::RECONFIRM_MONTHS),
            ]);

            AuditLogger::record('vehicle.create', $vehicle, data: ['source' => $source->value]);

            return $vehicle;
        });

        Completeness::forget();

        return $vehicle;
    }

    /**
     * "Vai šis auto vēl aktuāls?" — yes: next re-confirmation in 12 months.
     */
    public function reconfirm(User $user, Vehicle $vehicle): void
    {
        $vehicle->forceFill(['reconfirm_due_at' => now()->addMonths(self::RECONFIRM_MONTHS)])->save();
        AuditLogger::record('vehicle.reconfirm', $vehicle, [(int) $user->id]);
    }

    /**
     * Decrypt a plate on a policy-gated path (own vehicles, reveal, export, re-key).
     */
    public function plateOf(Vehicle $vehicle): string
    {
        return $this->vault->open(
            $vehicle->plate_ciphertext,
            new VaultContext((int) $vehicle->building_id, 'vehicles', (string) $vehicle->id),
            $vehicle->plate_key_version,
        );
    }

    private function markDeclared(User $user, Apartment $apartment, bool $hasNoVehicle): void
    {
        $declaration = ApartmentParkingDeclaration::query()->firstOrNew(['apartment_id' => $apartment->id]);
        $declaration->forceFill([
            'declared_at' => now(),
            'has_no_vehicle' => $hasNoVehicle,
            'declared_by_user_id' => $user->id,
        ])->save();
    }
}

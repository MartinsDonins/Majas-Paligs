<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Audit\AuditLogger;
use App\Enums\PermitSource;
use App\Enums\PermitStatus;
use App\Enums\PermitStatusReason;
use App\Enums\PermitType;
use App\Enums\VehicleStatus;
use App\Models\Apartment;
use App\Models\Permit;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Permits (PARK-001, parking_officer / building_admin): issue, replace on a plate change (old → returned,
 * replaced_by_permit_id → new; the history stays), return / lost / revoke. Guest permits are never plate-bound.
 * The database enforces "main needs a vehicle while active", "no overlapping active permit with the same number
 * and type" and "the vehicle belongs to the permit's apartment"; this service turns those into friendly errors.
 */
final class PermitService
{
    /**
     * @throws ParkingActionFailed vehicle_required | vehicle_mismatch | overlap | invalid_period
     */
    public function issue(
        User $officer,
        Apartment $apartment,
        string $number,
        PermitType $type,
        ?Vehicle $vehicle,
        CarbonImmutable $validFrom,
        ?CarbonImmutable $validTo = null,
        ?string $issuedBy = null,
        ?string $note = null,
        PermitSource $source = PermitSource::Officer,
        ?string $importRunId = null,
    ): Permit {
        $number = $this->normalizeNumber($number);

        if ($type === PermitType::Main && $vehicle === null) {
            throw new ParkingActionFailed('vehicle_required');
        }
        if ($vehicle !== null && ((int) $vehicle->apartment_id !== (int) $apartment->id || $vehicle->status !== VehicleStatus::Active)) {
            throw new ParkingActionFailed('vehicle_mismatch');
        }
        if ($validTo !== null && $validTo->lessThan($validFrom)) {
            throw new ParkingActionFailed('invalid_period');
        }

        $permit = $this->guarded(fn (): Permit => Permit::query()->create([
            'apartment_id' => $apartment->id,
            'number' => $number,
            'type' => $type,
            // Guest permits are not plate-bound (MVP-SPEC AC 9).
            'vehicle_id' => $type === PermitType::Main ? $vehicle?->id : null,
            'issued_by' => $issuedBy !== null && trim($issuedBy) !== '' ? mb_substr(trim($issuedBy), 0, 120) : null,
            'valid_from' => $validFrom->toDateString(),
            'valid_to' => $validTo?->toDateString(),
            'status' => PermitStatus::Active,
            'note_private' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            'source' => $source,
            'import_run_id' => $importRunId,
        ]));

        AuditLogger::record('permit.issue', $permit, data: ['type' => $type->value, 'permit_number' => $permit->number]);

        return $permit;
    }

    /**
     * Plate change: the old main permit is returned and a replacement (same type, same end date, number kept unless
     * a new one is given) is bound to the new vehicle. Returns the new permit.
     *
     * @throws ParkingActionFailed vehicle_mismatch | overlap
     */
    public function replace(User $officer, Permit $old, Vehicle $vehicle, ?string $newNumber = null): Permit
    {
        if ((int) $vehicle->apartment_id !== (int) $old->apartment_id || $vehicle->status !== VehicleStatus::Active) {
            throw new ParkingActionFailed('vehicle_mismatch');
        }

        $new = DB::transaction(function () use ($old, $vehicle, $newNumber): Permit {
            $old = Permit::query()->lockForUpdate()->findOrFail($old->id);
            $old->forceFill(['status' => PermitStatus::Returned, 'status_reason' => PermitStatusReason::Officer])->save();

            $today = CarbonImmutable::now('Europe/Riga')->startOfDay();
            $new = $this->guarded(fn (): Permit => Permit::query()->create([
                'apartment_id' => $old->apartment_id,
                'number' => $newNumber !== null && trim($newNumber) !== '' ? $this->normalizeNumber($newNumber) : $old->number,
                'type' => $old->type,
                'vehicle_id' => $old->type === PermitType::Main ? $vehicle->id : null,
                'issued_by' => $old->issued_by,
                'valid_from' => $today->toDateString(),
                'valid_to' => $old->valid_to !== null && $old->valid_to->greaterThanOrEqualTo($today) ? $old->valid_to->toDateString() : null,
                'status' => PermitStatus::Active,
                'source' => PermitSource::ChangeVehicle,
            ]));

            $old->forceFill(['replaced_by_permit_id' => $new->id])->save();

            return $new;
        });

        AuditLogger::record('permit.replace', $new, data: ['replaced_permit_id' => $old->id, 'permit_number' => $new->number]);

        return $new;
    }

    /**
     * Officer: returned / lost / revoked.
     */
    public function setStatus(User $officer, Permit $permit, PermitStatus $status): void
    {
        if ($status === PermitStatus::Active) {
            throw new ParkingActionFailed('invalid_status');
        }

        $permit->forceFill([
            'status' => $status,
            'status_reason' => $status === PermitStatus::Revoked ? PermitStatusReason::Officer : null,
        ])->save();

        AuditLogger::record('permit.status', $permit, data: ['status' => $status->value, 'permit_number' => $permit->number]);
    }

    /**
     * Permit issuer's list in "Izveidot vai atjaunināt" mode (PARK-012): validity / status / issued_by of an existing
     * ACTIVE permit with the same number + type in the same apartment. Never moves a permit to another apartment and
     * never touches its vehicle. Returns whether anything changed.
     *
     * @throws ParkingActionFailed invalid_period | overlap
     */
    public function updateFromImport(
        User $officer,
        Permit $permit,
        ?CarbonImmutable $validFrom,
        ?CarbonImmutable $validTo,
        PermitStatus $status,
        ?string $issuedBy,
    ): bool {
        $changes = [];
        $from = $validFrom ?? $permit->valid_from;
        $to = $validTo ?? $permit->valid_to;

        if ($to !== null && $to->lessThan($from)) {
            throw new ParkingActionFailed('invalid_period');
        }
        if ($validFrom !== null && ! $validFrom->equalTo($permit->valid_from)) {
            $changes['valid_from'] = $validFrom->toDateString();
        }
        if ($validTo !== null && ($permit->valid_to === null || ! $validTo->equalTo($permit->valid_to))) {
            $changes['valid_to'] = $validTo->toDateString();
        }
        $issuedBy = $issuedBy !== null && trim($issuedBy) !== '' ? mb_substr(trim($issuedBy), 0, 120) : null;
        if ($issuedBy !== null && $issuedBy !== $permit->issued_by) {
            $changes['issued_by'] = $issuedBy;
        }
        if ($status !== $permit->status) {
            $changes['status'] = $status;
            $changes['status_reason'] = $status === PermitStatus::Revoked ? PermitStatusReason::Officer : null;
        }

        if ($changes === []) {
            return false;
        }

        $this->guarded(fn () => $permit->forceFill($changes)->save());
        AuditLogger::record('permit.update', $permit, data: ['permit_number' => $permit->number, 'fields' => array_keys($changes), 'source' => 'import']);

        return true;
    }

    /**
     * Bulk "Pagarināt līdz…" (PARK-011): new end date for an active permit (never before its start).
     *
     * @throws ParkingActionFailed invalid_status | invalid_period | overlap
     */
    public function extend(User $officer, Permit $permit, CarbonImmutable $validTo): void
    {
        if ($permit->status !== PermitStatus::Active) {
            throw new ParkingActionFailed('invalid_status');
        }
        if ($validTo->lessThan($permit->valid_from)) {
            throw new ParkingActionFailed('invalid_period');
        }

        $this->guarded(fn () => $permit->forceFill(['valid_to' => $validTo->toDateString()])->save());
        AuditLogger::record('permit.extend', $permit, data: ['permit_number' => $permit->number, 'valid_to' => $validTo->toDateString()]);
    }

    /**
     * Canonical permit number (upper case, single spaces, 1–32 characters) — the import validator uses the same rule.
     *
     * @throws ParkingActionFailed invalid_number
     */
    public static function canonicalNumber(string $number): string
    {
        $number = strtoupper(trim((string) preg_replace('/\s+/', ' ', $number)));

        if ($number === '' || mb_strlen($number) > 32) {
            throw new ParkingActionFailed('invalid_number');
        }

        return $number;
    }

    private function normalizeNumber(string $number): string
    {
        return self::canonicalNumber($number);
    }

    /**
     * Map database constraint violations to friendly errors.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function guarded(callable $callback): mixed
    {
        try {
            return DB::transaction(static fn () => $callback());
        } catch (QueryException $e) {
            $state = (string) ($e->errorInfo[0] ?? $e->getCode());

            throw match ($state) {
                '23P01' => new ParkingActionFailed('overlap'),
                '23503' => new ParkingActionFailed('vehicle_mismatch'),
                '23514' => new ParkingActionFailed('vehicle_required'),
                default => $e,
            };
        }
    }
}

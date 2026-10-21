<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Audit\AuditLogger;
use App\Enums\ObservationSource;
use App\Enums\PermitType;
use App\Models\Permit;
use App\Models\PermitPlateObservation;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Guest-permit observations (PARK-011, ROUND2-SPEC §6.1–6.2, D8): "this car was seen with this guest permit". The
 * plate is sealed with PlateVault (AD table permit_plate_observations, this row's ULID) and indexed with the
 * building's blind index — never stored in plaintext and never bound to the permit. Officer / admin only ("Atzīmēt
 * redzēto auto", audited `permit.observe`) or the permit issuer's list import (counted in `import.commit`). It does
 * not consume the resident lookup quota and writes no plate_lookups row. Guest permits only (also a DB trigger).
 */
final class PermitObservationService
{
    public function __construct(private readonly PlateVault $vault) {}

    /**
     * @throws ParkingActionFailed not_guest | invalid_plate
     */
    public function record(
        User $officer,
        Permit $permit,
        string $typedPlate,
        ?CarbonImmutable $observedOn = null,
        ObservationSource $source = ObservationSource::Officer,
    ): PermitPlateObservation {
        if ($permit->type !== PermitType::Guest) {
            throw new ParkingActionFailed('not_guest');
        }

        $plate = PlateNormalizer::normalize($typedPlate) ?? throw new ParkingActionFailed('invalid_plate');
        $building = CurrentBuilding::get();
        $id = strtolower((string) Str::ulid());
        $sealed = $this->vault->seal($plate, new VaultContext((int) $building->id, 'permit_plate_observations', $id), $building->settings->plateIndexKeyVersion);
        $today = CarbonImmutable::now('Europe/Riga')->startOfDay();

        $observation = PermitPlateObservation::query()->create([
            'id' => $id,
            'permit_id' => $permit->id,
            'plate_ciphertext' => $sealed->ciphertext,
            'plate_bidx' => $sealed->bidx,
            'plate_key_version' => $sealed->keyVersion,
            'observed_on' => ($observedOn !== null && $observedOn->lessThanOrEqualTo($today) ? $observedOn : $today)->toDateString(),
            'source' => $source,
            'created_by' => $officer->id,
        ]);

        if ($source === ObservationSource::Officer) {
            AuditLogger::record('permit.observe', $permit, data: ['permit_number' => $permit->number, 'source' => $source->value]);
        }

        return $observation;
    }

    /**
     * Decrypt on a policy-gated path only (re-key, reveal).
     */
    public function plateOf(PermitPlateObservation $observation): string
    {
        return $this->vault->open(
            $observation->plate_ciphertext,
            new VaultContext((int) $observation->building_id, 'permit_plate_observations', (string) $observation->id),
            $observation->plate_key_version,
        );
    }
}

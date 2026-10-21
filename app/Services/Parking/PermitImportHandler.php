<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Enums\ImportKind;
use App\Enums\ObservationSource;
use App\Enums\PermitSource;
use App\Enums\PermitType;
use App\Enums\VehicleSource;
use App\Gdpr\ParkingGate;
use App\Models\Apartment;
use App\Models\ImportRun;
use App\Models\Permit;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Import\ImportFailed;
use App\Services\Import\ImportHandler;
use App\Services\Import\RowResult;
use App\Services\Import\RowValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * The permit issuer's list on the shared import pipeline (PARK-012, ROUND2-SPEC §6.3) — replaces the paste-only
 * PARK-001.2 flow. Everything (upload, mapping, validation, commit) needs parking gate L1 (D6) and parking staff
 * (`parking-import`). Rows go through the domain services only:
 *
 * - main permit → plate required → VehicleRegistry finds the apartment's active vehicle or creates one
 *   (source `seed_list`, active) → PermitService::issue (source `import`, import_run_id);
 * - guest permit → never plate-bound; a plate in the row becomes an observation (source `import`, today);
 * - `create_or_update` updates validity / status / issued_by of the existing active permit with the same number + type
 *   in the same apartment (never another apartment, never its vehicle).
 *
 * Reports carry row numbers + codes only; preview cells mask plates (`AB••34`).
 */
final class PermitImportHandler implements ImportHandler
{
    public function __construct(
        private readonly PlateVault $vault,
        private readonly VehicleRegistry $registry,
        private readonly PermitService $permits,
        private readonly PermitObservationService $observations,
    ) {}

    public function kind(): ImportKind
    {
        return ImportKind::Permits;
    }

    public function targets(): array
    {
        $apartment = ['dzivoklis', 'dz', 'dz nr', 'dzivokla nr', 'dzivokla numurs', 'kvartira', 'kv', 'nomer kvartiry', 'кв', 'квартира', 'номер квартиры', 'apartment', 'apt', 'flat'];

        return [
            'permit_number' => ['required' => true, 'max' => 32, 'synonyms' => ['caurlaide', 'caurlaides nr', 'caurlaides numurs', 'nr', 'propusk', 'пропуск', 'номер пропуска', '№ пропуска', 'permit', 'permit no', 'permit number']],
            'type' => ['required' => true, 'synonyms' => ['veids', 'tips', 'pamata viesu', 'caurlaides veids', 'tip', 'тип', 'основной гостевой', 'вид', 'type', 'main guest']],
            'apartment_number' => ['required' => true, 'synonyms' => $apartment],
            'plate' => ['required' => false, 'synonyms' => ['auto', 'numurs', 'auto numurs', 'valsts numurs', 'nomer avto', 'номер машины', 'госномер', 'номер авто', 'plate', 'plate number', 'registration']],
            'valid_from' => ['required' => false, 'synonyms' => ['no', 'derigs no', 'deriga no', 's', 'с', 'действует с', 'from', 'valid from']],
            'valid_to' => ['required' => false, 'synonyms' => ['lidz', 'derigs lidz', 'deriga lidz', 'do', 'до', 'действует до', 'to', 'valid until', 'valid to']],
            'status' => ['required' => false, 'synonyms' => ['statuss', 'status', 'статус', 'состояние']],
            'issued_by' => ['required' => false, 'max' => 120, 'synonyms' => ['izsniedza', 'izsniedzejs', 'vydal', 'выдал', 'кем выдан', 'issued by', 'issuer']],
            'company_car' => ['required' => false, 'synonyms' => ['uznemuma auto', 'dienesta auto', 'sluzhebnaya', 'служебная', 'служебная машина', 'company car']],
        ];
    }

    public function canImport(User $user): bool
    {
        return Gate::forUser($user)->allows('parking-import');
    }

    public function gateOpen(): bool
    {
        return ParkingGate::current()?->l1() ?? false;
    }

    public function assertGateOpen(): void
    {
        if (! $this->gateOpen()) {
            throw new ImportFailed('gate_closed');
        }
    }

    public function missingGateItems(): array
    {
        return ParkingGate::current()?->missingLabels(1) ?? [];
    }

    public function validator(ImportRun $run): RowValidator
    {
        return new PermitRowValidator($run, $this->vault);
    }

    public function mask(string $target, string $value): string
    {
        if ($target !== 'plate') {
            return $value;
        }

        $plate = PlateNormalizer::normalize($value);

        return $plate !== null ? PlateNormalizer::mask($plate) : str_repeat('•', min(8, mb_strlen($value)));
    }

    public function commitRow(User $actor, ImportRun $run, RowResult $row): string
    {
        $v = $row->values;
        /** @var PermitType $type */
        $type = $v['type'];
        /** @var Apartment $apartment */
        $apartment = $v['apartment'];
        $plate = $v['plate'];

        try {
            if ($row->action === 'update') {
                /** @var Permit $permit */
                $permit = $v['existing'];
                $changed = $this->permits->updateFromImport($actor, $permit, $v['valid_from'], $v['valid_to'], $v['status'], $v['issued_by']);
                $this->observe($actor, $permit, $type, $plate);

                return $changed ? 'updated' : 'skipped';
            }

            $vehicle = null;
            if ($type === PermitType::Main) {
                $vehicle = $v['vehicle'] instanceof Vehicle
                    ? $v['vehicle']
                    : $this->registry->officerCreate($actor, $apartment, (string) $plate, 'LV', (bool) $v['company_car'], VehicleSource::SeedList);
            }

            $permit = $this->permits->issue(
                $actor, $apartment, (string) $v['number'], $type, $vehicle,
                $v['valid_from'] ?? CarbonImmutable::now('Europe/Riga')->startOfDay(), $v['valid_to'], $v['issued_by'],
                source: PermitSource::Import, importRunId: (string) $run->id,
            );
            $this->observe($actor, $permit, $type, $plate);
        } catch (ParkingActionFailed $e) {
            throw new ImportFailed(match ($e->key) {
                'conflict', 'vehicle_mismatch' => 'plate_conflict',
                'invalid_plate' => 'plate_invalid',
                'overlap' => 'number_overlap',
                'invalid_period' => 'period_invalid',
                'vehicle_required' => 'main_without_plate',
                default => 'commit_error',
            });
        }

        return 'created';
    }

    private function observe(User $actor, Permit $permit, PermitType $type, ?string $plate): void
    {
        if ($type === PermitType::Guest && $plate !== null) {
            $this->observations->record($actor, $permit, $plate, null, ObservationSource::Import);
        }
    }
}

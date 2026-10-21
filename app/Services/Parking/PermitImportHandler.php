<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Enums\ObservationSource;
use App\Enums\PermitSource;
use App\Enums\PermitStatus;
use App\Enums\PermitType;
use App\Enums\VehicleSource;
use App\Gdpr\ParkingGate;
use App\Models\Apartment;
use App\Models\ImportRun;
use App\Models\ParkingLot;
use App\Models\Permit;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportField;
use App\Services\Import\ImportHandler;
use App\Services\Import\ImportRow;
use App\Services\Import\RowResult;
use App\Services\Import\RowValidator;
use App\Support\Keys;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * The permit issuer's list on the shared import pipeline (PARK-012 on DB-003, ROUND2-SPEC §6.3) — replaces the
 * paste-only PARK-001.2 flow. Every stage (upload, mapping, validation, commit) needs parking gate L1 (D6) and
 * parking staff (building_admin / parking_officer). Rows are written through the domain services only:
 *
 * - main permit → plate required → the apartment's active vehicle with that plate, or a new one (source `seed_list`,
 *   active, VehicleRegistry) → PermitService::issue (source `import`, import_run_id);
 * - guest permit → never plate-bound; a plate in the row becomes an observation (source `import`, today);
 * - `create_or_update` updates validity / status / issuer of the existing active permit with the same number + type
 *   in the same apartment (never another apartment, never its vehicle).
 *
 * Codes, never values (PermitRowValidator holds the cell parsers); preview cells mask plates (`AB••34`).
 */
final class PermitImportHandler implements ImportHandler
{
    public function __construct(
        private readonly PlateVault $vault,
        private readonly VehicleRegistry $registry,
        private readonly PermitService $permits,
        private readonly PermitObservationService $observations,
    ) {}

    public function kind(): string
    {
        return 'permits';
    }

    public function fields(): array
    {
        return [
            'permit_number' => new ImportField('permit_number', required: true, maxLength: 32, synonyms: [
                'caurlaide', 'caurlaides nr', 'caurlaides numurs', 'nr', 'propusk', 'пропуск', 'номер пропуска', '№ пропуска',
                'permit', 'permit no', 'permit number',
            ]),
            'type' => new ImportField('type', required: true, maxLength: 40, synonyms: [
                'veids', 'tips', 'pamata viesu', 'caurlaides veids', 'tip', 'тип', 'основной гостевой', 'вид', 'type', 'main guest',
            ]),
            'apartment_number' => new ImportField('apartment_number', required: true, maxLength: 16, synonyms: [
                'dzivoklis', 'dz', 'dz nr', 'dzivokla nr', 'dzivokla numurs', 'kvartira', 'kv', 'nomer kvartiry', 'кв', 'квартира',
                'номер квартиры', 'apartment', 'apt', 'flat',
            ]),
            'plate' => new ImportField('plate', maxLength: 20, synonyms: [
                'auto', 'numurs', 'auto numurs', 'valsts numurs', 'nomer avto', 'номер машины', 'госномер', 'номер авто', 'plate',
                'plate number', 'registration',
            ]),
            'valid_from' => new ImportField('valid_from', maxLength: 20, synonyms: ['no', 'derigs no', 'deriga no', 's', 'с', 'действует с', 'from', 'valid from']),
            'valid_to' => new ImportField('valid_to', maxLength: 20, synonyms: ['lidz', 'derigs lidz', 'deriga lidz', 'do', 'до', 'действует до', 'to', 'valid until', 'valid to']),
            'status' => new ImportField('status', maxLength: 40, synonyms: ['statuss', 'status', 'статус', 'состояние']),
            'issued_by' => new ImportField('issued_by', maxLength: 120, synonyms: ['izsniedza', 'izsniedzejs', 'vydal', 'выдал', 'кем выдан', 'issued by', 'issuer']),
            'company_car' => new ImportField('company_car', maxLength: 20, synonyms: ['uznemuma auto', 'dienesta auto', 'sluzhebnaya', 'служебная', 'служебная машина', 'company car']),
        ];
    }

    public function closedGate(): ?array
    {
        $status = ParkingGate::current();

        if ($status !== null && $status->l1()) {
            return null;
        }

        return $status?->missingLabels(1) ?? [];
    }

    /**
     * Parking staff at any level: below L1 the page shows the explanation card (the gate is checked separately).
     */
    public function mayImport(User $user): bool
    {
        return ! $user->isTombstone() && Gate::forUser($user)->allows('create', ParkingLot::class);
    }

    public function mayDownloadTemplate(User $user): bool
    {
        return $this->mayImport($user);
    }

    public function vaultKey(): string
    {
        return Keys::plateEncryption();
    }

    public function confirmations(): array
    {
        return ['endorsed'];
    }

    public function codes(): array
    {
        return [
            'type_unknown' => 'error',
            'plate_invalid' => 'error',
            'plate_invalid_guest' => 'warning',
            'main_without_plate' => 'error',
            'plate_conflict' => 'error',
            'number_overlap' => 'error',
            'date_invalid' => 'error',
            'period_invalid' => 'error',
            'status_unknown' => 'error',
            'apartment_change_refused' => 'error',
            'date_serial' => 'warning',
            'flag_unparsed' => 'warning',
            'inactive_skip' => 'skip',
        ];
    }

    public function template(string $locale): array
    {
        $headers = array_map(static fn (string $key): string => __('import.field.'.$key, [], $locale), array_keys($this->fields()));

        return [
            'headers' => $headers,
            'example' => [
                'P-001',
                PermitType::Main->label($locale),
                '1',
                '',
                CarbonImmutable::now('Europe/Riga')->startOfYear()->format('d.m.Y'),
                CarbonImmutable::now('Europe/Riga')->endOfYear()->format('d.m.Y'),
                PermitStatus::Active->label($locale),
                __('import.template.example_issuer', [], $locale),
                '',
            ],
        ];
    }

    public function dedupeKeys(ImportRow $row, RowResult $result): array
    {
        $type = PermitRowValidator::type($row->get('type'));
        $number = trim($row->get('permit_number'));

        return $type !== null && $number !== '' ? [$type->value.'|'.PermitRowValidator::number($number)] : [];
    }

    public function prepare(ImportContext $context, array $rows): void
    {
        // Main-permit plates of earlier rows of this pass (file order): a plate listed for two apartments in one file
        // is a conflict even before the first row is written. Across commit chunks the database check covers it.
        $context->cache['plates'] = [];
    }

    public function validate(ImportRow $row, RowResult $result, ImportContext $context): void
    {
        $number = PermitRowValidator::number($row->get('permit_number'));
        $type = PermitRowValidator::type($row->get('type'));
        if ($type === null) {
            $result->add('type_unknown');
        }

        $from = PermitRowValidator::date($row->get('valid_from'), $result);
        $to = PermitRowValidator::date($row->get('valid_to'), $result);
        if ($from === false || $to === false) {
            $result->add('date_invalid');
        }
        $from = $from instanceof CarbonImmutable ? $from : null;
        $to = $to instanceof CarbonImmutable ? $to : null;
        if ($to !== null && $to->lessThan($from ?? CarbonImmutable::now('Europe/Riga')->startOfDay())) {
            $result->add('period_invalid');
        }

        $status = PermitStatus::Active;
        if ($row->has('status')) {
            $parsed = PermitRowValidator::status($row->get('status'));
            if ($parsed === null) {
                $result->add('status_unknown');
            } else {
                $status = $parsed;
            }
        }

        $issuedBy = $row->has('issued_by') ? trim($row->get('issued_by')) : null;

        $company = PermitRowValidator::yesNo($row->get('company_car'));
        if ($company === 'unparsed') {
            $result->add('flag_unparsed');
            $company = null;
        }

        $plateRaw = trim($row->get('plate'));
        $plate = $plateRaw !== '' ? PlateNormalizer::normalize($plateRaw) : null;
        if ($type === PermitType::Main && $plateRaw === '' && ! $context->updates()) {
            $result->add('main_without_plate');
        } elseif ($plateRaw !== '' && $plate === null) {
            $result->add($type === PermitType::Guest ? 'plate_invalid_guest' : 'plate_invalid');
        }

        $result->values = [
            'number' => $number, 'type' => $type?->value, 'plate' => $plate, 'valid_from' => $from?->toDateString(),
            'valid_to' => $to?->toDateString(), 'status' => $status->value, 'issued_by' => $issuedBy, 'company_car' => $company === true,
            'vehicle_id' => null,
        ];
        $result->preview = [
            'permit_number' => $number,
            'type' => $type?->label() ?? '',
            'apartment_number' => (string) $result->apartmentNumber,
            'plate' => $plate !== null ? PlateNormalizer::mask($plate) : ($plateRaw !== '' ? str_repeat('•', min(8, mb_strlen($plateRaw))) : ''),
            'valid_from' => $from?->format('d.m.Y') ?? '',
            'valid_to' => $to?->format('d.m.Y') ?? '',
            'status' => $status->label(),
            'issued_by' => (string) $issuedBy,
            'company_car' => $company === true ? __('import.value.yes') : '',
        ];

        if ($type !== null && $result->apartmentId !== null && ! $this->hasError($result)) {
            $this->againstDatabase($result, $context, $number, $type, (int) $result->apartmentId, $plate, $from, $to, $status);
        }

        if ($type === PermitType::Main && $plate !== null && $result->apartmentId !== null && ! isset($context->cache['plates'][$plate])) {
            $context->cache['plates'][$plate] = (int) $result->apartmentId;
        }
    }

    public function commit(RowResult $result, ImportRun $run, User $actor): string
    {
        $v = $result->values;
        $type = PermitType::from((string) $v['type']);
        $plate = $v['plate'];
        $from = $v['valid_from'] !== null ? CarbonImmutable::parse((string) $v['valid_from'], 'Europe/Riga') : null;
        $to = $v['valid_to'] !== null ? CarbonImmutable::parse((string) $v['valid_to'], 'Europe/Riga') : null;

        if ($result->action === 'update' && $result->targetId !== null) {
            $permit = Permit::query()->whereKey((int) $result->targetId)->firstOrFail();
            $changed = $this->permits->updateFromImport($actor, $permit, $from, $to, PermitStatus::from((string) $v['status']), $v['issued_by']);
            $this->observe($actor, $permit, $type, $plate);

            return $changed ? 'updated' : 'skipped';
        }

        $apartment = Apartment::query()->findOrFail((int) $result->apartmentId);

        $vehicle = null;
        if ($type === PermitType::Main) {
            $vehicle = $v['vehicle_id'] !== null
                ? Vehicle::query()->findOrFail((int) $v['vehicle_id'])
                : $this->registry->officerCreate($actor, $apartment, (string) $plate, 'LV', (bool) $v['company_car'], VehicleSource::SeedList);
        }

        $permit = $this->permits->issue(
            $actor, $apartment, (string) $v['number'], $type, $vehicle,
            $from ?? CarbonImmutable::now('Europe/Riga')->startOfDay(), $to, $v['issued_by'],
            source: PermitSource::Import, importRunId: (string) $run->id,
        );
        $this->observe($actor, $permit, $type, $plate);

        return 'created';
    }

    /**
     * Existing permits, number overlap, plate conflicts — the dry-run and the commit-time re-validation.
     */
    private function againstDatabase(RowResult $r, ImportContext $context, string $number, PermitType $type, int $apartmentId, ?string $plate, ?CarbonImmutable $from, ?CarbonImmutable $to, PermitStatus $status): void
    {
        $start = $from ?? CarbonImmutable::now('Europe/Riga')->startOfDay();
        $same = Permit::query()->active()->where('number', $number)->where('type', $type->value)->get();

        $mine = $same->first(fn (Permit $p): bool => (int) $p->apartment_id === $apartmentId);
        $elsewhere = $same->first(fn (Permit $p): bool => (int) $p->apartment_id !== $apartmentId
            && ($p->valid_to === null || ! $p->valid_to->lessThan($start))
            && ($to === null || ! $to->lessThan($p->valid_from)));

        if ($mine !== null) {
            $r->targetId = (string) $mine->id;
            if ($context->updates()) {
                $r->action = 'update';
                $r->add('exists_update');
            } else {
                $r->action = 'skip';
                $r->add('exists_skip');
            }

            return;
        }

        if ($elsewhere !== null) {
            $r->add($context->updates() ? 'apartment_change_refused' : 'number_overlap');

            return;
        }

        if ($status !== PermitStatus::Active) {
            $r->action = 'skip';
            $r->add('inactive_skip');

            return;
        }

        $r->action = 'create';

        if ($type === PermitType::Main) {
            if ($plate === null) {
                $r->add('main_without_plate');

                return;
            }

            $vehicle = Vehicle::query()->active()->withBidx($this->vault->currentBidx(CurrentBuilding::get(), $plate))->first();
            if ($vehicle !== null && (int) $vehicle->apartment_id !== $apartmentId) {
                $r->add('plate_conflict');

                return;
            }
            $inFile = $context->cache['plates'][$plate] ?? null;
            if ($inFile !== null && $inFile !== $apartmentId) {
                $r->add('plate_conflict');

                return;
            }
            $r->values['vehicle_id'] = $vehicle?->id;
        }
    }

    private function hasError(RowResult $result): bool
    {
        $severity = RowValidator::severity($this);

        foreach ($result->codes as $code) {
            if (($severity[$code] ?? 'error') === 'error') {
                return true;
            }
        }

        return false;
    }

    private function observe(User $actor, Permit $permit, PermitType $type, ?string $plate): void
    {
        if ($type === PermitType::Guest && $plate !== null) {
            $this->observations->record($actor, $permit, $plate, null, ObservationSource::Import);
        }
    }
}

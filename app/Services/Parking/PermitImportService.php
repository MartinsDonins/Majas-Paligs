<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Audit\AuditLogger;
use App\Enums\PermitType;
use App\Enums\VehicleSource;
use App\Gdpr\ParkingGate;
use App\Models\Apartment;
use App\Models\User;
use App\Models\Vehicle;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PermitImportPage back end (PARK-001.2): the permit issuer's existing list as CSV (`;` or `,`), one permit per row:
 * number; type (main|guest|pamata|viesu); plate (main only); apartment number; valid_from; valid_to; issued_by.
 * Only at gate L1 (policy + model guards). Vehicles are created ACTIVE with source `seed_list`; a plate already active
 * elsewhere is skipped (row number + reason only — the report never echoes a plate). Each row is its own savepoint.
 */
final class PermitImportService
{
    public const MAX_ROWS = 1000;

    public function __construct(
        private readonly VehicleRegistry $registry,
        private readonly PermitService $permits,
        private readonly PlateVault $vault,
    ) {}

    /**
     * @return array{permits: int, vehicles: int, skipped: list<array{row: int, reason: string}>}
     */
    public function import(User $officer, string $csv): array
    {
        // Rows are imported one savepoint each (errors become skipped rows), so the gate is asserted up front.
        ParkingGate::assertLevel(1);

        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $report = ['permits' => 0, 'vehicles' => 0, 'skipped' => []];

        foreach (array_slice($lines, 0, self::MAX_ROWS + 1) as $i => $line) {
            $row = $i + 1;
            if (trim($line) === '') {
                continue;
            }

            $cells = array_map('trim', str_getcsv($line, substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',', '"', ''));
            if ($i === 0 && preg_match('/^(nr|number|numurs|no\.?)$/i', (string) ($cells[0] ?? '')) === 1) {
                continue; // header row
            }

            try {
                $created = DB::transaction(fn (): bool => $this->row($officer, $cells));
                $report['permits']++;
                $report['vehicles'] += $created ? 1 : 0;
            } catch (ParkingActionFailed $e) {
                $report['skipped'][] = ['row' => $row, 'reason' => $e->key];
            } catch (Throwable) {
                $report['skipped'][] = ['row' => $row, 'reason' => 'invalid_row'];
            }
        }

        if (count($lines) > self::MAX_ROWS + 1) {
            $report['skipped'][] = ['row' => self::MAX_ROWS + 1, 'reason' => 'too_many_rows'];
        }

        AuditLogger::record('permit.import', null, data: ['permits' => $report['permits'], 'vehicles' => $report['vehicles'], 'skipped' => count($report['skipped'])]);

        return $report;
    }

    /**
     * @param  list<string>  $cells
     * @return bool whether a vehicle was created
     */
    private function row(User $officer, array $cells): bool
    {
        [$number, $type, $plate, $apartmentNo, $from, $to, $issuedBy] = array_pad($cells, 7, '');

        $type = match (mb_strtolower($type)) {
            'main', 'pamata', 'p' => PermitType::Main,
            'guest', 'viesu', 'v' => PermitType::Guest,
            default => throw new ParkingActionFailed('invalid_type'),
        };

        $apartment = Apartment::query()->active()->where('number', (int) $apartmentNo)->first()
            ?? throw new ParkingActionFailed('unknown_apartment');

        $validFrom = $this->date($from) ?? CarbonImmutable::now('Europe/Riga')->startOfDay();
        $validTo = $this->date($to);
        $vehicle = null;
        $created = false;

        if ($type === PermitType::Main) {
            $normalized = PlateNormalizer::normalize($plate) ?? throw new ParkingActionFailed('invalid_plate');
            $bidx = $this->vault->currentBidx(CurrentBuilding::get(), $normalized);
            $vehicle = Vehicle::query()->active()->withBidx($bidx)->first();

            if ($vehicle !== null && (int) $vehicle->apartment_id !== (int) $apartment->id) {
                throw new ParkingActionFailed('conflict');
            }

            if ($vehicle === null) {
                $vehicle = $this->registry->officerCreate($officer, $apartment, $normalized, 'LV', false, VehicleSource::SeedList);
                $created = true;
            }
        }

        $this->permits->issue($officer, $apartment, $number, $type, $vehicle, $validFrom, $validTo, $issuedBy !== '' ? $issuedBy : null);

        return $created;
    }

    private function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$format, $value, 'Europe/Riga');
            } catch (Throwable) {
                $d = null;
            }
            if ($d instanceof CarbonImmutable && $d->format($format) === $value) {
                return $d;
            }
        }

        throw new ParkingActionFailed('invalid_date');
    }
}

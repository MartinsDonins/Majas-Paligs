<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Audit\AuditLogger;
use App\Enums\BuildingRoleType;
use App\Enums\ParkingExportFormat;
use App\Enums\ParkingExportKind;
use App\Enums\PermitType;
use App\Gdpr\CsvWriter;
use App\Models\ParkingExport;
use App\Models\Permit;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CounterService;
use App\Tenancy\CurrentBuilding;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Police / manager permit lists (PARK-005 → PARK-013, MVP-SPEC C.5, ROUND2-SPEC §6.4): request (building_admin) →
 * approve (a DIFFERENT building_admin or the parking_officer; DB CHECK approved_by <> requested_by) → download via a
 * 10-minute signed URL. The file is generated on demand and never stored; only the metadata row (number
 * PE-YYYY-NNNN, who/when, row count) remains. CSV (UTF-8 BOM, `;`, formula-safe) or A4 PDF with a header block.
 *
 * "Caurlaižu saraksts" columns:
 * - police (only in enforcement mode plate_list|both): plate (decrypted at generation), country, permit number,
 *   type, valid to — one row per active vehicle (+ its main permit) and per active guest permit. No apartments.
 * - manager: permit number, type, valid from / to, status, stairwell — no plates. Apartment numbers only when the
 *   request asked for them (`include_apartments`), which needs the house's parking rules
 *   (settings.parking_rules_adopted_at) — DB CHECK manager-only, policy and this service.
 */
final class ParkingExportService
{
    public const VALID_DAYS = 7;

    public function __construct(
        private readonly CounterService $counters,
        private readonly VehicleRegistry $registry,
    ) {}

    /**
     * @throws ParkingActionFailed mode | rules_not_adopted
     */
    public function request(User $admin, ParkingExportKind $kind, ParkingExportFormat $format, bool $includeApartments = false): ParkingExport
    {
        if ($kind === ParkingExportKind::Police && ! self::policeEnabled()) {
            throw new ParkingActionFailed('mode');
        }
        if ($includeApartments && ($kind !== ParkingExportKind::Manager || ! self::apartmentsAllowed())) {
            throw new ParkingActionFailed('rules_not_adopted');
        }

        $export = DB::transaction(fn (): ParkingExport => ParkingExport::query()->create([
            'number' => $this->counters->nextNumber('parking_export'),
            'kind' => $kind,
            'format' => $format,
            'include_apartments' => $includeApartments,
            'requested_by' => $admin->id,
            'requested_role' => BuildingRoleType::BuildingAdmin->value,
            'requested_at' => now(),
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]));

        AuditLogger::record('parking_export.request', $export, data: ['export_number' => $export->number, 'kind' => $kind->value, 'include_apartments' => $includeApartments]);

        return $export;
    }

    public function approve(User $approver, ParkingExport $export): void
    {
        $role = $approver->isBuildingAdmin(CurrentBuilding::get()) ? BuildingRoleType::BuildingAdmin : BuildingRoleType::ParkingOfficer;

        $export->forceFill(['approved_by' => $approver->id, 'approved_role' => $role->value, 'approved_at' => now()])->save();
        AuditLogger::record('parking_export.approve', $export, data: ['export_number' => $export->number]);
    }

    public static function policeEnabled(): bool
    {
        return in_array(CurrentBuilding::get()->settings->parkingEnforcementMode, ['plate_list', 'both'], true);
    }

    /**
     * Manager list with apartment numbers — only after the house adopted parking rules.
     */
    public static function apartmentsAllowed(): bool
    {
        return CurrentBuilding::get()->settings->parkingRulesAdoptedAt !== null;
    }

    public static function downloadUrl(ParkingExport $export): string
    {
        return URL::temporarySignedRoute('building.parking-export', now()->addMinutes((int) config('majas.exports.signed_minutes', 10)), [
            'building' => CurrentBuilding::get()->slug,
            'export' => $export->id,
        ]);
    }

    /**
     * Generate the file in memory (never stored), record generated_at + row_count, audit the download.
     */
    public function download(User $user, ParkingExport $export, string $locale = 'lv'): Response
    {
        $rows = $this->rows($export);
        $export->forceFill(['generated_at' => now(), 'row_count' => count($rows)])->save();
        AuditLogger::record('parking_export.download', $export, data: ['export_number' => $export->number, 'rows' => count($rows)]);

        $header = $this->header($export, $user, $locale);
        $columns = $this->columns($export);
        $filename = $export->number.'.'.$export->format->value;
        $headers = ['Cache-Control' => 'no-store, private'];
        $t = static fn (string $k): string => __('parking.export.'.$k, [], $locale);
        $cells = fn (array $r): array => array_map(fn (string $c): string => $this->cell($r, $c, $locale), $columns);

        if ($export->format === ParkingExportFormat::Pdf) {
            return Pdf::loadView('pdf.parking-export', [
                'header' => $header,
                'columns' => array_map(static fn (string $c): string => $t('col_'.$c), $columns),
                'plateColumn' => array_search('plate', $columns, true),
                'rows' => array_map($cells, $rows),
                'kind' => $export->kind->value,
                'locale' => $locale,
                'footer' => $t($export->include_apartments ? 'footer_apartments' : 'footer'),
            ])
                ->setPaper('a4', $export->kind === ParkingExportKind::Manager ? 'landscape' : 'portrait')
                ->download($filename)
                ->withHeaders($headers);
        }

        $csv = [];
        foreach ($header as $label => $value) {
            $csv[] = [$label, $value];
        }
        $csv[] = [];
        $csv[] = array_map(static fn (string $c): string => $t('col_'.$c), $columns);
        foreach ($rows as $r) {
            $csv[] = $cells($r);
        }

        return response(CsvWriter::toString($csv, ';'), 200, $headers + [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Column keys of the list (labels: parking.export.col_<key>).
     *
     * @return list<string>
     */
    public function columns(ParkingExport $export): array
    {
        if ($export->kind === ParkingExportKind::Police) {
            return ['plate', 'country', 'permit', 'type', 'valid_to'];
        }

        return $export->include_apartments
            ? ['permit', 'type', 'valid_from', 'valid_to', 'status', 'stairwell', 'apartment']
            : ['permit', 'type', 'valid_from', 'valid_to', 'status', 'stairwell'];
    }

    /**
     * Police: one row per ACTIVE vehicle (+ its active main permit) and one per active guest permit (no plate).
     * Manager: one row per active permit — never a plate; the apartment only with include_apartments.
     *
     * @return list<array{plate: string, country: string, permit: string, type: string|null, valid_from: string, valid_to: string, status: string, stairwell: string, apartment: string}>
     */
    public function rows(ParkingExport $export): array
    {
        $today = CarbonImmutable::now('Europe/Riga')->startOfDay();
        $permits = Permit::query()->active()
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today->toDateString()))
            ->with(['apartment:id,number,section_id', 'apartment.section:id,number'])
            ->orderBy('number')->get();
        $out = [];

        if ($export->kind === ParkingExportKind::Police) {
            $vehicles = Vehicle::query()->active()->get()->keyBy('id');
            $mainByVehicle = $permits->where('type', PermitType::Main)->keyBy('vehicle_id');
            foreach ($vehicles as $vehicle) {
                $out[] = $this->row($this->registry->plateOf($vehicle), $vehicle->country, $mainByVehicle->get($vehicle->id), $today, false);
            }
            foreach ($permits->where('type', PermitType::Guest) as $permit) {
                $out[] = $this->row('', '', $permit, $today, false);
            }

            return $out;
        }

        foreach ($permits as $permit) {
            $out[] = $this->row('', '', $permit, $today, $export->include_apartments);
        }

        return $out;
    }

    /**
     * @return array{plate: string, country: string, permit: string, type: string|null, valid_from: string, valid_to: string, status: string, stairwell: string, apartment: string}
     */
    private function row(string $plate, string $country, ?Permit $permit, CarbonImmutable $today, bool $withApartment): array
    {
        return [
            'plate' => $plate,
            'country' => $country,
            'permit' => $permit !== null ? $permit->number : '',
            'type' => $permit?->type->value,
            'valid_from' => $permit?->valid_from->format('d.m.Y') ?? '',
            'valid_to' => $permit?->valid_to?->format('d.m.Y') ?? '',
            'status' => $permit !== null ? $permit->displayState($today) : '',
            'stairwell' => $permit?->apartment?->section !== null ? (string) $permit->apartment->section->number : '',
            'apartment' => $withApartment && $permit?->apartment !== null ? (string) $permit->apartment->number : '',
        ];
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function cell(array $row, string $column, string $locale): string
    {
        return match ($column) {
            'type' => $row['type'] !== null ? __('enum.permit_type.'.$row['type'], [], $locale) : '',
            'status' => $row['status'] !== '' && $row['status'] !== null ? __('parking.permit_state.'.$row['status'], [], $locale) : '',
            default => (string) ($row[$column] ?? ''),
        };
    }

    /**
     * @return array<string, string>
     */
    private function header(ParkingExport $export, User $user, string $locale): array
    {
        $b = CurrentBuilding::get();
        $t = static fn (string $k): string => __('parking.export.'.$k, [], $locale);
        $role = $user->isBuildingAdmin($b) ? BuildingRoleType::BuildingAdmin : BuildingRoleType::ParkingOfficer;

        return [
            $t('title') => __('enum.parking_export_kind.'.$export->kind->value, [], $locale),
            $t('building') => $b->address_line.', '.$b->city,
            $t('controller') => (string) $b->settings->controller['name'],
            $t('number') => $export->number,
            $t('generated_at') => now('Europe/Riga')->format('d.m.Y H:i'),
            $t('generated_by') => __('enum.building_role.'.$role->value, [], $locale),
        ];
    }
}

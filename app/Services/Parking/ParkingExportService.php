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
 * Police / manager lists (PARK-005, MVP-SPEC C.5): request (building_admin) → approve (a DIFFERENT building_admin
 * or the parking_officer; DB CHECK approved_by <> requested_by) → download via a 10-minute signed URL. The file is
 * generated on demand and never stored; only the metadata row (number PE-YYYY-NNNN, who/when, row count) remains.
 * The police list exists only in enforcement mode plate_list|both. Fields: plate, country, permit number, type,
 * valid_to — no apartment numbers. CSV cells are formula-injection safe.
 */
final class ParkingExportService
{
    public const VALID_DAYS = 7;

    public function __construct(
        private readonly CounterService $counters,
        private readonly VehicleRegistry $registry,
    ) {}

    /**
     * @throws ParkingActionFailed mode
     */
    public function request(User $admin, ParkingExportKind $kind, ParkingExportFormat $format): ParkingExport
    {
        if ($kind === ParkingExportKind::Police && ! self::policeEnabled()) {
            throw new ParkingActionFailed('mode');
        }

        $export = DB::transaction(fn (): ParkingExport => ParkingExport::query()->create([
            'number' => $this->counters->nextNumber('parking_export'),
            'kind' => $kind,
            'format' => $format,
            'requested_by' => $admin->id,
            'requested_role' => BuildingRoleType::BuildingAdmin->value,
            'requested_at' => now(),
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]));

        AuditLogger::record('parking_export.request', $export, data: ['export_number' => $export->number, 'kind' => $kind->value]);

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
        $filename = $export->number.'.'.$export->format->value;
        $headers = ['Cache-Control' => 'no-store, private'];

        if ($export->format === ParkingExportFormat::Pdf) {
            return Pdf::loadView('pdf.parking-export', ['header' => $header, 'rows' => $rows, 'kind' => $export->kind->value, 'locale' => $locale])
                ->setPaper('a4', 'portrait')
                ->download($filename)
                ->withHeaders($headers);
        }

        $t = static fn (string $k): string => __('parking.export.'.$k, [], $locale);
        $csv = [];
        foreach ($header as $label => $value) {
            $csv[] = [$label, $value];
        }
        $csv[] = [];
        $csv[] = [$t('col_plate'), $t('col_country'), $t('col_permit'), $t('col_type'), $t('col_valid_to')];
        foreach ($rows as $r) {
            $csv[] = [$r['plate'], $r['country'], $r['permit'], $r['type'] !== null ? __('enum.permit_type.'.$r['type'], [], $locale) : '', $r['valid_to']];
        }

        return response(CsvWriter::toString($csv), 200, $headers + [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Police: one row per ACTIVE vehicle (+ its active main permit) and one per active guest permit (no plate).
     * Manager: one row per active permit (plate for main permits only). Never an apartment number.
     *
     * @return list<array{plate: string, country: string, permit: string, type: string|null, valid_to: string}>
     */
    public function rows(ParkingExport $export): array
    {
        $today = CarbonImmutable::now('Europe/Riga')->toDateString();
        $permits = Permit::query()->active()
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->orderBy('number')->get();
        $vehicles = Vehicle::query()->active()->get()->keyBy('id');
        $out = [];

        if ($export->kind === ParkingExportKind::Police) {
            $mainByVehicle = $permits->where('type', PermitType::Main)->keyBy('vehicle_id');
            foreach ($vehicles as $vehicle) {
                $permit = $mainByVehicle->get($vehicle->id);
                $out[] = $this->row($this->registry->plateOf($vehicle), $vehicle->country, $permit);
            }
            foreach ($permits->where('type', PermitType::Guest) as $permit) {
                $out[] = $this->row('', '', $permit);
            }

            return $out;
        }

        foreach ($permits as $permit) {
            $vehicle = $permit->vehicle_id !== null ? $vehicles->get($permit->vehicle_id) : null;
            $out[] = $this->row($vehicle !== null ? $this->registry->plateOf($vehicle) : '', $vehicle !== null ? $vehicle->country : '', $permit);
        }

        return $out;
    }

    /**
     * @return array{plate: string, country: string, permit: string, type: string|null, valid_to: string}
     */
    private function row(string $plate, string $country, ?Permit $permit): array
    {
        return [
            'plate' => $plate,
            'country' => $country,
            'permit' => $permit !== null ? $permit->number : '',
            'type' => $permit?->type->value,
            'valid_to' => $permit?->valid_to?->format('d.m.Y') ?? '',
        ];
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

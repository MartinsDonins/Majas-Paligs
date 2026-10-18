<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\ParkingExportFormat;
use App\Enums\ParkingExportKind;
use App\Enums\PermitType;
use App\Models\ParkingExport;
use App\Services\Parking\ParkingActionFailed;
use App\Services\Parking\ParkingExportService;
use App\Services\Parking\PermitService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/*
 * MVP-SPEC C.8 Feature/Parking/ExportTest (AC 8): four eyes, police list only in plate_list|both mode, CSV injection
 * safe, signed 10-minute expiry, nothing stored, no apartment numbers.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->b = parkingHouse('export-house');
    $this->b->forceFill(['settings' => $this->b->settings->with(['parking_enforcement_mode' => 'both'])])->save();
    $this->admin = makeUser('export-admin@example.test');
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->admin2 = makeUser('export-admin2@example.test');
    grantRole($this->admin2, $this->b, BuildingRoleType::BuildingAdmin);
    $this->officer = parkingOfficer($this->b, 'export-officer@example.test');

    $car = activeVehicle($this->b, 7, 'AB1234', $this->officer);
    inBuilding($this->b, function () use ($car): void {
        app(PermitService::class)->issue($this->officer, apartment($this->b, 7), '=HYPERLINK("x")', PermitType::Main, $car, CarbonImmutable::now(), CarbonImmutable::now()->addYear());
        app(PermitService::class)->issue($this->officer, apartment($this->b, 7), 'V-7', PermitType::Guest, null, CarbonImmutable::now());
    });
});

function requestExport($test, ParkingExportKind $kind = ParkingExportKind::Police, ParkingExportFormat $format = ParkingExportFormat::Csv): ParkingExport
{
    return inBuilding($test->b, fn () => app(ParkingExportService::class)->request($test->admin, $kind, $format));
}

it('needs a second, different person to approve (policy and DB CHECK)', function (): void {
    $export = requestExport($this);
    expect($export->number)->toMatch('/^PE-\d{4}-0001$/');

    inBuilding($this->b, function () use ($export): void {
        expect(Gate::forUser($this->admin)->allows('approve', $export))->toBeFalse()
            ->and(Gate::forUser($this->admin)->allows('download', $export))->toBeFalse()
            ->and(Gate::forUser($this->officer)->allows('approve', $export))->toBeTrue()
            ->and(Gate::forUser($this->admin2)->allows('approve', $export))->toBeTrue();

        expect(fn () => DB::transaction(fn () => DB::table('parking_exports')->where('id', $export->id)->update(['approved_by' => $this->admin->id])))
            ->toThrow(QueryException::class);

        app(ParkingExportService::class)->approve($this->officer, $export);
        expect(Gate::forUser($this->admin)->allows('download', $export->refresh()))->toBeTrue()
            ->and(Gate::forUser($this->admin2)->allows('download', $export))->toBeFalse();
    });
});

it('offers the police list only in enforcement mode plate_list|both', function (): void {
    $this->b->forceFill(['settings' => $this->b->settings->with(['parking_enforcement_mode' => 'visible_permit'])])->save();

    expect(fn () => requestExport($this))->toThrow(ParkingActionFailed::class, 'mode')
        ->and(requestExport($this, ParkingExportKind::Manager)->kind)->toBe(ParkingExportKind::Manager);
});

it('downloads an injection-safe CSV via a signed 10-minute URL, never stored, without apartment numbers', function (): void {
    $export = requestExport($this);
    inBuilding($this->b, fn () => app(ParkingExportService::class)->approve($this->officer, $export));
    $url = inBuilding($this->b, fn () => ParkingExportService::downloadUrl($export));

    $this->actingAs($this->admin);
    app(StepUpService::class)->markFresh($this->admin, 'email');
    $response = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $csv = $response->getContent();

    expect($csv)->toContain('AB1234')
        ->toContain("'=HYPERLINK")
        ->not->toContain(',=HYPERLINK')
        ->toContain($export->number)
        ->toContain(__('enum.building_role.building_admin'))
        ->not->toContain('Dz. 7');
    expect(Storage::disk('local')->allFiles())->toBe([]);

    $export->refresh();
    expect($export->generated_at)->not->toBeNull()->and($export->row_count)->toBe(2);

    $this->travel(11)->minutes();
    app(StepUpService::class)->markFresh($this->admin, 'email');
    $this->get($url)->assertForbidden();
});

it('renders the PDF list for the approver', function (): void {
    $export = requestExport($this, ParkingExportKind::Manager, ParkingExportFormat::Pdf);
    inBuilding($this->b, fn () => app(ParkingExportService::class)->approve($this->admin2, $export));
    $url = inBuilding($this->b, fn () => ParkingExportService::downloadUrl($export));

    $this->actingAs($this->admin2);
    app(StepUpService::class)->markFresh($this->admin2, 'email');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('refuses downloads of unapproved or expired exports and to residents', function (): void {
    $export = requestExport($this);
    $url = inBuilding($this->b, fn () => ParkingExportService::downloadUrl($export));

    $this->actingAs($this->admin);
    app(StepUpService::class)->markFresh($this->admin, 'email');
    $this->get($url)->assertForbidden();

    $resident = parkingResident($this->b, 1);
    $this->actingAs($resident);
    app(StepUpService::class)->markFresh($resident, 'email');
    $this->get($url)->assertForbidden();
});

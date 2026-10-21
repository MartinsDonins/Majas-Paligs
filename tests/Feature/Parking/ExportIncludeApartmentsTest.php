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
 * ROUND2-SPEC §12 Feature/Parking/ExportIncludeApartmentsTest (PARK-013, AC-R2-17): the manager permit list has no
 * plates and no apartment numbers by default; `include_apartments` only after parking_rules_adopted_at (service,
 * policy, DB CHECK manager-only); the police list (plate, country, permit, type, valid to) only in plate-list modes;
 * four eyes and 10-minute signed links unchanged. CSV is `;`-separated with a BOM.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->b = parkingHouse('mgr-export');
    $this->admin = makeUser('mgr-admin@example.test');
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->officer = parkingOfficer($this->b, 'mgr-officer@example.test');

    $car = activeVehicle($this->b, 13, 'AB1234', $this->officer);
    inBuilding($this->b, function () use ($car): void {
        app(PermitService::class)->issue($this->officer, apartment($this->b, 13), 'P-13', PermitType::Main, $car, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::now()->addYear());
        app(PermitService::class)->issue($this->officer, apartment($this->b, 14), 'V-14', PermitType::Guest, null, CarbonImmutable::parse('2026-09-01'));
    });
});

function mgrDownload($test, ParkingExport $export): string
{
    inBuilding($test->b, fn () => app(ParkingExportService::class)->approve($test->officer, $export));
    $url = inBuilding($test->b, fn () => ParkingExportService::downloadUrl($export));
    $test->actingAs($test->admin);
    app(StepUpService::class)->markFresh($test->admin, 'totp');

    return (string) $test->get($url)->assertOk()->getContent();
}

it('lists permits for the manager without plates and without apartment numbers by default', function (): void {
    $export = inBuilding($this->b, fn () => app(ParkingExportService::class)->request($this->admin, ParkingExportKind::Manager, ParkingExportFormat::Csv));
    $csv = mgrDownload($this, $export);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain('P-13;'.__('enum.permit_type.main'))
        ->toContain('V-14;'.__('enum.permit_type.guest'))
        ->toContain(__('parking.export.col_stairwell'))
        ->not->toContain('AB1234')
        ->not->toContain(__('parking.export.col_apartment'))
        ->not->toContain(';13;')->not->toContain(';14');

    $rows = inBuilding($this->b, fn () => app(ParkingExportService::class)->rows($export));
    expect(collect($rows)->pluck('apartment')->unique()->all())->toBe([''])
        ->and(collect($rows)->pluck('plate')->unique()->all())->toBe([''])
        ->and(collect($rows)->pluck('stairwell')->all())->toBe(['2', '2']);
});

it('includes apartment numbers only after the house adopted parking rules', function (): void {
    inBuilding($this->b, function (): void {
        expect(fn () => app(ParkingExportService::class)->request($this->admin, ParkingExportKind::Manager, ParkingExportFormat::Csv, true))
            ->toThrow(ParkingActionFailed::class, 'rules_not_adopted')
            ->and(Gate::forUser($this->admin)->allows('includeApartments', ParkingExport::class))->toBeFalse();
    });

    $this->b->forceFill(['settings' => $this->b->settings->with(['parking_rules_adopted_at' => '2026-10-01', 'parking_enforcement_mode' => 'both'])])->save();

    inBuilding($this->b, function (): void {
        expect(Gate::forUser($this->admin)->allows('includeApartments', ParkingExport::class))->toBeTrue()
            ->and(Gate::forUser($this->officer)->allows('includeApartments', ParkingExport::class))->toBeFalse()
            ->and(fn () => app(ParkingExportService::class)->request($this->admin, ParkingExportKind::Police, ParkingExportFormat::Csv, true))
            ->toThrow(ParkingActionFailed::class, 'rules_not_adopted');

        // DB CHECK: include_apartments is a manager-only flag.
        $police = app(ParkingExportService::class)->request($this->admin, ParkingExportKind::Police, ParkingExportFormat::Csv);
        expect(fn () => DB::transaction(fn () => DB::table('parking_exports')->where('id', $police->id)->update(['include_apartments' => true])))
            ->toThrow(QueryException::class);
    });

    $export = inBuilding($this->b, fn () => app(ParkingExportService::class)->request($this->admin, ParkingExportKind::Manager, ParkingExportFormat::Csv, true));
    $csv = mgrDownload($this, $export);

    expect($export->include_apartments)->toBeTrue()
        ->and($csv)->toContain(__('parking.export.col_apartment'))->toContain(';13')->toContain(';14')
        ->not->toContain('AB1234');
});

it('keeps the police list to plate-list modes with plates and no apartments', function (): void {
    inBuilding($this->b, fn () => expect(fn () => app(ParkingExportService::class)->request($this->admin, ParkingExportKind::Police, ParkingExportFormat::Csv))
        ->toThrow(ParkingActionFailed::class, 'mode'));

    $this->b->forceFill(['settings' => $this->b->settings->with(['parking_enforcement_mode' => 'plate_list'])])->save();
    $export = inBuilding($this->b, fn () => app(ParkingExportService::class)->request($this->admin, ParkingExportKind::Police, ParkingExportFormat::Csv));
    $csv = mgrDownload($this, $export);

    expect($csv)->toContain('AB1234;LV;P-13;'.__('enum.permit_type.main'))
        ->toContain(';;V-14;'.__('enum.permit_type.guest'))
        ->not->toContain(__('parking.export.col_apartment'))
        ->not->toContain(__('parking.export.col_stairwell'));
});

it('renders both lists as PDF', function (): void {
    $this->b->forceFill(['settings' => $this->b->settings->with(['parking_rules_adopted_at' => '2026-10-01'])])->save();
    $export = inBuilding($this->b, fn () => app(ParkingExportService::class)->request($this->admin, ParkingExportKind::Manager, ParkingExportFormat::Pdf, true));

    inBuilding($this->b, fn () => app(ParkingExportService::class)->approve($this->officer, $export));
    $url = inBuilding($this->b, fn () => ParkingExportService::downloadUrl($export));
    $this->actingAs($this->admin);
    app(StepUpService::class)->markFresh($this->admin, 'totp');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

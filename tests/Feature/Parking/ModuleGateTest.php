<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\LookupReason;
use App\Enums\PermitType;
use App\Filament\Manage\Pages\BuildingSettingsPage;
use App\Gdpr\ParkingGate;
use App\Gdpr\ParkingGateClosed;
use App\Gdpr\ParkingGateMonitor;
use App\Models\ApartmentParkingDeclaration;
use App\Models\AuditEntry;
use App\Models\BuildingPrivacyNotice;
use App\Models\ParkingLot;
use App\Models\Permit;
use App\Models\PlateLookup;
use App\Models\Sighting;
use App\Models\Vehicle;
use App\Services\Parking\PermitImportService;
use App\Services\Parking\VehicleRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * MVP-SPEC C.8 Feature/Parking/ModuleGateTest (AC 1): two-level gate L1 / L2, enforced in policies, model guards and
 * routes/UI; regression switches the module off, blocks writes and is audited.
 */
const RESIDENT_PARKING_PATHS = ['', '/mine', '/declare', '/sighting', '/progress'];

function residentParkingOk(string $slug, $test, $user): void
{
    foreach (RESIDENT_PARKING_PATHS as $p) {
        $test->actingAs($user)->get("/b/{$slug}/parking{$p}")->assertOk();
    }
}

function residentParking404(string $slug, $test, $user): void
{
    foreach (RESIDENT_PARKING_PATHS as $p) {
        $test->actingAs($user)->get("/b/{$slug}/parking{$p}")->assertNotFound();
    }
}

it('L0: refuses every personal-data write from any path but allows lots', function (): void {
    $b = parkingHouse('gate-l0', level: 0);
    $officer = parkingOfficer($b);
    $apartment = apartment($b, 1);

    inBuilding($b, function () use ($officer, $apartment, $b): void {
        expect(ParkingGate::status($b)->level)->toBe(0);

        // Lots (no personal data) are fine at L0.
        expect(ParkingLot::query()->create(['name' => 'Pagalms'])->exists)->toBeTrue();

        // Service / console / seeder style writes hit the model guard.
        expect(fn () => app(VehicleRegistry::class)->officerCreate($officer, $apartment, 'AB1234'))->toThrow(ParkingGateClosed::class)
            ->and(fn () => Permit::query()->create(['apartment_id' => $apartment->id, 'number' => 'G-1', 'type' => PermitType::Guest, 'valid_from' => '2026-10-01']))->toThrow(ParkingGateClosed::class)
            ->and(fn () => ApartmentParkingDeclaration::query()->create(['apartment_id' => $apartment->id, 'declared_at' => now(), 'has_no_vehicle' => true]))->toThrow(ParkingGateClosed::class)
            ->and(fn () => PlateLookup::query()->create(['user_id' => $officer->id, 'plate_bidx' => random_bytes(32), 'result' => false, 'reason_code' => LookupReason::Other]))->toThrow(ParkingGateClosed::class)
            ->and(fn () => Sighting::query()->create(['id' => strtolower((string) Str::ulid()), 'parking_lot_id' => 1, 'plate_ciphertext' => 'x', 'plate_bidx' => random_bytes(32), 'plate_key_version' => 1, 'seen_at' => now(), 'reporter_apartment_id' => $apartment->id]))->toThrow(ParkingGateClosed::class)
            ->and(fn () => app(PermitImportService::class)->import($officer, 'P-1;guest;;1;01.10.2026;;Tests'))->toThrow(ParkingGateClosed::class);

        // Policies deny officer abilities below L1.
        expect($officer->can('viewAny', Vehicle::class))->toBeFalse()
            ->and($officer->can('create', Permit::class))->toBeFalse()
            ->and($officer->can('parking-import'))->toBeFalse()
            ->and($officer->can('create', ParkingLot::class))->toBeTrue();
    });

    expect(inBuilding($b, fn () => Vehicle::query()->count()))->toBe(0);
});

it('L0: Filament parking resources and the import page are unavailable, the preparation page is not', function (): void {
    $b = parkingHouse('gate-l0-panel', level: 0);
    $officer = parkingOfficer($b);
    steppedUp($officer);

    foreach (['vehicles', 'permits', 'sightings', 'parking-officer-tasks', 'parking-exports', 'permit-import', 'parking-analytics'] as $page) {
        $this->actingAs($officer)->get("/manage/gate-l0-panel/{$page}")->assertForbidden();
    }
    $this->actingAs($officer)->get('/manage/gate-l0-panel/parking')->assertOk()->assertSee(__('gate.parking.missing.dpa_scope_parking'));
    $this->actingAs($officer)->get('/manage/gate-l0-panel/parking-lots')->assertOk();
});

it('L1: officer prepares data, residents get 404 everywhere and no nav item or tiles', function (): void {
    $b = parkingHouse('gate-l1', level: 1);
    $officer = parkingOfficer($b);
    $resident = parkingResident($b, 1);

    $vehicle = activeVehicle($b, 1, 'AB1234', $officer);
    expect($vehicle->status->value)->toBe('active');

    $report = inBuilding($b, fn () => app(PermitImportService::class)->import($officer, "P-1;pamata;CD5678;2;01.10.2026;;Tests\nV-1;viesu;;2;01.10.2026;;Tests"));
    expect($report['permits'])->toBe(2)->and($report['skipped'])->toBe([]);

    residentParking404('gate-l1', $this, $resident);
    $this->actingAs($resident)->get('/b/gate-l1')->assertOk()
        ->assertDontSee(route('building.parking', $b))
        ->assertDontSee(__('home.action.check_car'));

    steppedUp($officer);
    foreach (['vehicles', 'permits', 'sightings', 'parking-officer-tasks', 'parking-exports', 'permit-import', 'parking-analytics'] as $page) {
        $this->actingAs($officer)->get("/manage/gate-l1/{$page}")->assertOk();
    }
});

it('L1: the module toggle is refused until L2; at L2 the same page enables it (step-up, audited)', function (): void {
    $b = parkingHouse('gate-toggle', level: 1);
    $admin = makeUser();
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($b);
    app(StepUpService::class)->markFresh($admin, 'email');

    inBuilding($b, fn () => Livewire::actingAs($admin)->test(BuildingSettingsPage::class)
        ->set('data.module_parking', true)
        ->call('save')
        ->assertNotified(__('admin.settings.parking_needs_l2')));
    expect($b->refresh()->settings->moduleEnabled('parking'))->toBeFalse();

    // Complete L2 in the same save (DPIA + mandate) with a notice covering parking already published.
    $b2 = parkingHouse('gate-toggle-2', level: 2, module: false);
    grantRole($admin, $b2, BuildingRoleType::BuildingAdmin);
    Filament::setTenant($b2);
    inBuilding($b2, fn () => Livewire::actingAs($admin)->test(BuildingSettingsPage::class)
        ->set('data.module_parking', true)
        ->call('save')
        ->assertHasNoErrors());
    expect($b2->refresh()->settings->moduleEnabled('parking'))->toBeTrue();

    // Without a fresh step-up a parking gate field change is refused.
    app(StepUpService::class)->forget();
    inBuilding($b2, fn () => Livewire::actingAs($admin)->test(BuildingSettingsPage::class)
        ->set('data.module_parking', false)
        ->call('save'));
    expect($b2->refresh()->settings->moduleEnabled('parking'))->toBeTrue();
});

it('L2: module on → resident flows, nav item and Home tiles', function (): void {
    $b = parkingHouse('gate-l2', level: 2);
    $resident = parkingResident($b, 1);

    expect(inBuilding($b, fn () => ParkingGate::status($b)->residentEnabled()))->toBeTrue();
    residentParkingOk('gate-l2', $this, $resident);
    $this->actingAs($resident)->get('/b/gate-l2')->assertOk()
        ->assertSee(route('building.parking', $b))
        ->assertSee(__('home.action.check_car'));
});

it('L2 with the module OFF: resident routes still 404', function (): void {
    $b = parkingHouse('gate-l2-off', level: 2, module: false);
    residentParking404('gate-l2-off', $this, parkingResident($b, 1));
});

it('regression L2 → L1: module switched off automatically, audited, resident routes 404', function (): void {
    $b = parkingHouse('gate-reg2', level: 2);
    $resident = parkingResident($b, 1);

    inBuilding($b, function () use ($b): void {
        $before = ParkingGate::status($b);
        $b->forceFill(['settings' => $b->settings->with(['mandate_ref' => null])])->save();
        $after = ParkingGateMonitor::afterChange($b, $before);

        expect($after->level)->toBe(1)->and($after->moduleOn)->toBeFalse()
            ->and(AuditEntry::query()->where('building_id', $b->id)->where('action', 'gate.parking.module_off')->exists())->toBeTrue()
            ->and(AuditEntry::query()->where('building_id', $b->id)->where('action', 'gate.parking.changed')->exists())->toBeTrue();
    });

    expect($b->refresh()->settings->moduleEnabled('parking'))->toBeFalse();
    residentParking404('gate-reg2', $this, $resident);
});

it('regression L1 → L0 (DPA scope without parking): writes refused at once', function (): void {
    $b = parkingHouse('gate-reg1', level: 1);
    $officer = parkingOfficer($b);
    activeVehicle($b, 1, 'AB1234', $officer);

    inBuilding($b, function () use ($b, $officer): void {
        $before = ParkingGate::status($b);
        $b->forceFill(['settings' => $b->settings->with(['dpa_scope' => ['core', 'heating', 'outages']])])->save();
        expect(ParkingGateMonitor::afterChange($b, $before)->level)->toBe(0);

        expect(fn () => app(VehicleRegistry::class)->officerCreate($officer, apartment($b, 2), 'CD5678'))->toThrow(ParkingGateClosed::class)
            ->and($officer->can('viewAny', Vehicle::class))->toBeFalse();
    });
});

it('withdrawing the parking privacy notice drops L2 (notice must cover parking)', function (): void {
    $b = parkingHouse('gate-notice', level: 2);

    inBuilding($b, function () use ($b): void {
        $before = ParkingGate::status($b);
        BuildingPrivacyNotice::current()?->forceFill(['withdrawn_at' => now()])->save();
        $after = ParkingGateMonitor::afterChange($b, $before);

        expect($after->level)->toBe(1)->and($after->missingL2)->toContain('privacy_notice_parking');
    });
});

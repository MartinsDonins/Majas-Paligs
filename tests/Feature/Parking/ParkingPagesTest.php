<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\PermitType;
use App\Gdpr\GdprExporter;
use App\Livewire\Parking\ChangeVehicleWizard;
use App\Livewire\Parking\DeclarationWizard;
use App\Livewire\Parking\Lookup;
use App\Livewire\Parking\MyPermits;
use App\Livewire\Parking\SightingDispute;
use App\Livewire\Parking\SightingSheet;
use App\Models\ParkingLot;
use App\Models\Sighting;
use App\Models\Vehicle;
use App\Services\Parking\PermitService;
use App\Services\Parking\SightingService;
use App\Services\Parking\VehicleRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\LegalNoticeSeeder;
use Livewire\Livewire;

/*
 * Package C screens (DESIGN-SYSTEM §6.6–6.8, MVP-SPEC C.3/C.6): every resident page and every Filament parking page
 * renders; the HTTP/Livewire-level plate lookup smoke test (the real-browser Browser/PlateLookupSmokeTest is
 * TEST-003.1); "Mainīt auto", "Mums nav auto", "Esmu viesis", legal banner, sign PDF and the GDPR export.
 */
beforeEach(function (): void {
    (new LegalNoticeSeeder)->run();
    $this->b = parkingHouse('pages-house');
    $this->admin = makeUser('pages-admin@example.test');
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->officer = parkingOfficer($this->b, 'pages-officer@example.test');
    $this->resident = parkingResident($this->b, 1);
    inBuilding($this->b, fn () => ParkingLot::query()->create(['name' => 'Pagalms']));
});

it('renders every resident parking page in LV and RU', function (): void {
    foreach (['', '/mine', '/declare', '/sighting', '/progress'] as $p) {
        $this->actingAs($this->resident)->get("/b/pages-house/parking{$p}")->assertOk()->assertSee(__('nav.parking'));
    }

    $this->actingAs($this->resident)->get('/b/pages-house/parking')
        ->assertSee(__('parking.lookup.question'))
        ->assertSee('pie vējstikla jābūt skaidri redzamai caurlaidei'); // seeded legal banner

    $this->resident->forceFill(['locale' => 'ru'])->save();
    $this->actingAs($this->resident)->get('/b/pages-house/parking')->assertOk()
        ->assertSee('Проверить авто')
        ->assertSee('за лобовым стеклом');
});

it('smoke: lookup → not found → report → thanks (HTTP + Livewire level)', function (): void {
    activeVehicle($this->b, 9, 'AB1234', $this->officer);

    $this->actingAs($this->resident)->get('/b/pages-house/parking')->assertOk()->assertSee('data-plate', false);

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(Lookup::class)
        ->call('check', 'ZZ 0001')
        ->assertSee(__('parking.lookup.not_found'))
        ->assertSee(__('parking.lookup.report'))
        ->call('again')
        ->call('check', 'ab-1234')
        ->assertSee(__('parking.lookup.registered')));

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(SightingSheet::class)
        ->call('submit', 'ZZ0001')
        ->assertSee(__('parking.sighting.thanks'))
        ->assertSee(__('parking.sighting.state.submitted')));
});

it('runs "Mainīt auto": pending + the permit stays until the officer replaces it', function (): void {
    $car = activeVehicle($this->b, 1, 'AB1234', $this->officer);
    $permit = inBuilding($this->b, fn () => app(PermitService::class)->issue($this->officer, apartment($this->b, 1), 'P-55', PermitType::Main, $car, CarbonImmutable::now()));

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(MyPermits::class)
        ->assertSee(__('parking.mine.counts', ['main' => 1, 'guest' => 0]))
        ->assertSee(__('parking.mine.permit_no', ['number' => 'P-55']))
        ->assertSee('AB1234'));

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(ChangeVehicleWizard::class, ['permitId' => $permit->id])
        ->call('submit', 'NEW123')
        ->assertSet('state', 'confirm_return')
        ->set('returnOld', true)
        ->call('submit', 'NEW123')
        ->assertSet('state', 'pending')
        ->assertSee(__('parking.declare.thanks')));

    expect(inBuilding($this->b, fn () => Vehicle::query()->where('status', 'pending')->count()))->toBe(1)
        ->and(inBuilding($this->b, fn () => $permit->refresh()->status->value))->toBe('active');
});

it('declares "mums nav auto" and refuses it while a car is registered', function (): void {
    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(DeclarationWizard::class)
        ->call('declareNoCar')
        ->assertSet('step', 'no_car_done'));

    activeVehicle($this->b, 1, 'AB1234', $this->officer);
    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(DeclarationWizard::class)
        ->call('declareNoCar')
        ->assertSee(__('parking.declare.has_vehicles')));
});

it('"Esmu viesis" gives the same neutral answer whether or not a report exists', function (): void {
    $lot = inBuilding($this->b, fn () => ParkingLot::query()->firstOrFail());
    inBuilding($this->b, fn () => app(SightingService::class)->submit(parkingResident($this->b, 2), apartment($this->b, 2), $lot, 'GU1234'));

    $with = inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(SightingDispute::class)->call('submit', 'GU1234')->assertSee(__('parking.dispute.thanks'))->html());
    $without = inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(SightingDispute::class)->call('submit', 'NO0000')->html());

    $strip = fn (string $h): string => (string) preg_replace('/wire:(snapshot|id|effects)="[^"]*"/', '', $h);
    expect($strip($with))->toBe($strip($without))
        ->and(inBuilding($this->b, fn () => Sighting::query()->value('status')->value))->toBe('disputed');
});

it('renders every Filament parking page for building_admin and the officer', function (): void {
    activeVehicle($this->b, 2, 'CD5678', $this->officer);

    foreach ([$this->admin, $this->officer] as $user) {
        // A new person = a new session (AuthenticateSession binds the session to the account that logged in).
        $this->flushSession();
        steppedUp($user);
        foreach (['parking', 'parking-lots', 'vehicles', 'permits', 'sightings', 'parking-officer-tasks', 'parking-exports', 'parking-analytics', 'permit-import', 'settings'] as $page) {
            if ($page === 'settings' && $user === $this->officer) {
                continue;
            }
            $this->actingAs($user)->get("/admin/b/pages-house/{$page}")->assertOk();
        }
    }
});

it('downloads the Art. 14 parking sign as PDF (available from L0)', function (): void {
    $l0 = parkingHouse('sign-l0', level: 0);
    $officer = parkingOfficer($l0);

    $this->actingAs($officer)->get('/b/sign-l0/files/parking-sign?lot=Pagalms')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->resident)->get('/b/pages-house/files/parking-sign')->assertForbidden();
});

it('includes own vehicles, permits, lookups and reported sightings in the GDPR export', function (): void {
    inBuilding($this->b, fn () => app(VehicleRegistry::class)->declare($this->resident, apartment($this->b, 1), 'AB1234'));
    inBuilding($this->b, fn () => app(SightingService::class)->submit($this->resident, apartment($this->b, 1), ParkingLot::query()->firstOrFail(), 'ZZ0001'));

    $payload = (new GdprExporter('x'))->collect($this->resident);
    $b = $payload['buildings'][0];

    expect($b['vehicles'][0]['plate'])->toBe('AB1234')
        ->and($b['vehicles'][0]['status'])->toBe('pending')
        ->and($b['plate_lookups'])->toHaveCount(2)
        ->and($b['sightings_reported'][0]['plate'])->toBe('ZZ0001')
        ->and(json_encode($b['sightings_reported']))->not->toContain('matched');
});

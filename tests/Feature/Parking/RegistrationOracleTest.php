<?php

declare(strict_types=1);

use App\Enums\LookupReason;
use App\Enums\OfficerTaskKind;
use App\Livewire\Parking\DeclarationWizard;
use App\Livewire\Parking\SightingSheet;
use App\Models\ParkingLot;
use App\Models\ParkingOfficerTask;
use App\Models\PlateLookup;
use App\Models\Vehicle;
use App\Services\Parking\DeclarationResult;
use App\Services\Parking\VehicleRegistry;
use Livewire\Livewire;
use Tests\TestCase;

/*
 * MVP-SPEC C.8 Feature/Parking/RegistrationOracleTest (AC 16): declaring a plate that is ACTIVE in another
 * apartment answers exactly like any other declaration, creates a pending vehicle flagged `conflict` for the officer,
 * writes a plate_lookups row and consumes quota; sighting payloads never contain matched_registered / repeat.
 */
beforeEach(function (): void {
    $this->b = parkingHouse('oracle-house');
    $this->officer = parkingOfficer($this->b);
    activeVehicle($this->b, 9, 'AB1234', $this->officer);
    $this->resident = parkingResident($this->b, 1);
    inBuilding($this->b, fn () => ParkingLot::query()->create(['name' => 'Pagalms']));
});

function declareHtml(TestCase $test, string $plate): string
{
    return inBuilding($test->b, fn () => Livewire::actingAs($test->resident)->test(DeclarationWizard::class)
        ->call('choose', 'car')
        ->call('declareCar', $plate)
        ->assertSet('step', 'done')
        ->html());
}

it('gives byte-identical answers for a conflicting and a fresh plate', function (): void {
    $conflict = declareHtml($this, 'AB1234');
    $fresh = declareHtml($this, 'CD5678');

    $strip = fn (string $html): string => (string) preg_replace('/wire:(snapshot|id|key)="[^"]*"|wire:effects="[^"]*"/', '', $html);
    expect($strip($conflict))->toBe($strip($fresh))->toContain(__('parking.declare.thanks'));
});

it('stores the conflicting declaration as pending + conflict with an officer task, never touching the active row', function (): void {
    $result = inBuilding($this->b, fn () => app(VehicleRegistry::class)->declare($this->resident, apartment($this->b, 1), 'АВ-1234'));
    expect($result->status)->toBe(DeclarationResult::PENDING);

    inBuilding($this->b, function (): void {
        $pending = Vehicle::query()->where('apartment_id', apartment($this->b, 1)->id)->firstOrFail();
        expect($pending->status->value)->toBe('pending')->and($pending->conflict)->toBeTrue()
            ->and(Vehicle::query()->active()->count())->toBe(1)
            ->and(ParkingOfficerTask::query()->where('vehicle_id', $pending->id)->value('kind'))->toBe(OfficerTaskKind::VehicleConflict)
            ->and(PlateLookup::query()->where('reason_code', LookupReason::Declaration->value)->count())->toBe(1);
    });
});

it('consumes the lookup quota: the 6th declared plate in an hour waits for the countdown', function (): void {
    foreach (['A1', 'A2', 'A3', 'A4', 'A5'] as $p) {
        expect(inBuilding($this->b, fn () => app(VehicleRegistry::class)->declare($this->resident, apartment($this->b, 1), $p))->status)->toBe('pending');
    }
    $sixth = inBuilding($this->b, fn () => app(VehicleRegistry::class)->declare($this->resident, apartment($this->b, 1), 'A6'));
    expect($sixth->status)->toBe(DeclarationResult::RATE_LIMITED)->and($sixth->retryAfter)->toBeGreaterThan(0)
        ->and(inBuilding($this->b, fn () => Vehicle::query()->where('status', 'pending')->count()))->toBe(5);
});

it('never puts matched_registered or the repeat flag in a reporter payload; each sighting is a lookup', function (): void {
    $component = inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(SightingSheet::class)
        ->call('submit', 'AB1234')
        ->assertSet('state', 'submitted')
        ->assertSee(__('parking.sighting.thanks')));

    $payload = json_encode($component->snapshot).$component->html();
    expect($payload)->not->toContain('matched')->not->toContain('registered')->not->toContain('repeat')
        ->not->toContain(__('parking.lookup.registered'));

    $fresh = inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(SightingSheet::class)->call('submit', 'ZZ0000')->html());
    $strip = fn (string $html): string => (string) preg_replace('/wire:(snapshot|id|key)="[^"]*"|wire:effects="[^"]*"|<ul>.*<\/ul>/s', '', $html);
    expect($strip($component->html()))->toBe($strip($fresh));

    expect(inBuilding($this->b, fn () => PlateLookup::query()->where('reason_code', LookupReason::Sighting->value)->count()))->toBe(2);
});

<?php

declare(strict_types=1);

use App\Enums\LookupReason;
use App\Enums\OfficerTaskKind;
use App\Enums\PermitStatus;
use App\Enums\PermitStatusReason;
use App\Enums\PermitType;
use App\Gdpr\AccountEraser;
use App\Livewire\Parking\MyPermits;
use App\Models\AuditEntry;
use App\Models\ParkingOfficerTask;
use App\Models\Permit;
use App\Models\Vehicle;
use App\Services\Parking\PermitService;
use App\Services\Parking\PlateLookupService;
use App\Services\Parking\VehicleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * MVP-SPEC C.8 Feature/Parking/VehicleRemovalTest (AC 12): a vehicle bound to an active main permit is removed via
 * MyPermits ("Dzēst manu numuru") and AccountEraser without a CHECK violation — the permit becomes `revoked` with the
 * reason and an officer task; the plate stops matching at once; a raw DELETE outside the service still fails.
 * (prune:vehicles, the third path, runs as majas_retention in VehicleRetentionTest.)
 */
beforeEach(function (): void {
    $this->b = parkingHouse('removal-house');
    $this->officer = parkingOfficer($this->b);
    $this->owner = parkingResident($this->b, 1);

    // The resident declared the car; the officer verified it and bound a main permit.
    inBuilding($this->b, fn () => app(VehicleRegistry::class)->declare($this->owner, apartment($this->b, 1), 'AB1234'));
    $this->car = inBuilding($this->b, fn () => app(VehicleRegistry::class)->activate($this->officer, Vehicle::query()->firstOrFail()));
    $this->permit = inBuilding($this->b, fn () => app(PermitService::class)->issue($this->officer, apartment($this->b, 1), 'P-100', PermitType::Main, $this->car, CarbonImmutable::now()));
});

function assertRemoved($test, PermitStatusReason $reason): void
{
    inBuilding($test->b, function () use ($test, $reason): void {
        $permit = Permit::query()->findOrFail($test->permit->id);

        expect(Vehicle::query()->count())->toBe(0)
            ->and($permit->status)->toBe(PermitStatus::Revoked)
            ->and($permit->status_reason)->toBe($reason)
            ->and($permit->vehicle_id)->toBeNull()
            ->and(ParkingOfficerTask::query()->where('permit_id', $permit->id)->value('kind'))->toBe(OfficerTaskKind::PermitWithoutVehicle)
            ->and(AuditEntry::query()->where('building_id', $test->b->id)->where('action', 'vehicle.remove')->where('reason_code', $reason->value)->exists())->toBeTrue()
            ->and(app(PlateLookupService::class)->lookup(parkingResident($test->b, 2), 'AB1234', LookupReason::OwnCheck)->registered)->toBeFalse();
    });
}

it('removes the vehicle through MyPermits with a 2-step confirmation that warns about the bound permit', function (): void {
    inBuilding($this->b, fn () => Livewire::actingAs($this->owner)->test(MyPermits::class)
        ->assertSee('AB1234') // own plate is shown to its owner
        ->call('askRemove', $this->car->id)
        ->assertSee(__('parking.vehicle.remove_bound', ['number' => 'P-100']))
        ->assertSee(__('parking.change.open'))
        ->call('remove')
        ->assertSee(__('parking.mine.flash.removed')));

    assertRemoved($this, PermitStatusReason::VehicleRemoved);
});

it('removes own vehicles on account deletion (AccountEraser)', function (): void {
    app(AccountEraser::class)->erase($this->owner);

    assertRemoved($this, PermitStatusReason::AccountDeleted);
});

it('still fails at DB level when a bound vehicle is deleted outside the service', function (): void {
    expect(fn () => inBuilding($this->b, fn () => DB::transaction(fn () => DB::table('vehicles')->where('id', $this->car->id)->delete())))
        ->toThrow(QueryException::class);

    expect(inBuilding($this->b, fn () => Vehicle::query()->count()))->toBe(1);
});

it('does not let a neighbour remove somebody else’s vehicle', function (): void {
    $neighbour = parkingResident($this->b, 2);

    inBuilding($this->b, fn () => Livewire::actingAs($neighbour)->test(MyPermits::class)
        ->call('askRemove', $this->car->id)
        ->assertNotFound());
});

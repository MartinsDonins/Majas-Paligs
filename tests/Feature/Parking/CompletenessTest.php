<?php

declare(strict_types=1);

use App\Livewire\Parking\CampaignProgress;
use App\Models\Apartment;
use App\Models\ApartmentParkingDeclaration;
use App\Models\Vehicle;
use App\Services\Parking\Completeness;
use App\Services\Parking\VehicleRegistry;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * MVP-SPEC C.8 Feature/Parking/CompletenessTest (AC 14): floor(100 × covered / total); covered = ≥ 1 ACTIVE vehicle
 * or a "mums nav auto" declaration within 12 months; pending vehicles and archived apartments do not count.
 */
it('computes 55 % for 100 covered apartments out of 180 (Pūces 51 shape, AC 14)', function (): void {
    $b = parkingHouse('complete-180', sections: 5, floors: 9);
    $officer = parkingOfficer($b);

    inBuilding($b, function () use ($officer): void {
        $ids = Apartment::query()->orderBy('number')->limit(100)->pluck('id');
        expect(Apartment::query()->count())->toBe(180);

        foreach ($ids as $i => $id) {
            ApartmentParkingDeclaration::query()->create(['apartment_id' => $id, 'declared_at' => now(), 'has_no_vehicle' => true, 'declared_by_user_id' => $officer->id]);
        }
        Completeness::forget();

        expect(app(Completeness::class)->forBuilding())->toBe(['percent' => 55, 'covered' => 100, 'total' => 180]);
    });
});

it('counts active vehicles and fresh no-car declarations, not pending vehicles, stale declarations or archived apartments', function (): void {
    $b = parkingHouse('complete-mix', sections: 1, floors: 1); // 4 apartments
    $officer = parkingOfficer($b);
    $resident = parkingResident($b, 2);

    activeVehicle($b, 1, 'AB1234', $officer);                                                         // covered
    inBuilding($b, fn () => app(VehicleRegistry::class)->declare($resident, apartment($b, 2), 'CD5678')); // pending → not
    inBuilding($b, function () use ($b, $officer): void {
        ApartmentParkingDeclaration::query()->create(['apartment_id' => apartment($b, 3)->id, 'declared_at' => now()->subMonths(13), 'has_no_vehicle' => true, 'declared_by_user_id' => $officer->id]);
        Completeness::forget();
        expect(app(Completeness::class)->forBuilding())->toBe(['percent' => 25, 'covered' => 1, 'total' => 4]);

        DB::table('apartments')->where('id', apartment($b, 4)->id)->update(['archived_at' => now()]);
        Completeness::forget();
        expect(app(Completeness::class)->forBuilding())->toBe(['percent' => 33, 'covered' => 1, 'total' => 3]);
    });
});

it('refreshes after an officer verifies a pending vehicle', function (): void {
    $b = parkingHouse('complete-verify', sections: 1, floors: 1);
    $officer = parkingOfficer($b);
    $resident = parkingResident($b, 1);

    inBuilding($b, function () use ($b, $officer, $resident): void {
        app(VehicleRegistry::class)->declare($resident, apartment($b, 1), 'AB1234');
        expect(app(Completeness::class)->forBuilding()['covered'])->toBe(0);

        app(VehicleRegistry::class)->activate($officer, Vehicle::query()->firstOrFail());
        expect(app(Completeness::class)->forBuilding()['covered'])->toBe(1);
    });
});

it('shows per-stairwell rings with counts only to residents', function (): void {
    $b = parkingHouse('complete-rings');
    activeVehicle($b, 13, 'AB1234');
    $resident = parkingResident($b, 1);

    inBuilding($b, fn () => Livewire::actingAs($resident)->test(CampaignProgress::class)
        ->assertSee(__('parking.progress.stairwell', ['n' => 1]))
        ->assertSee(__('parking.progress.caption', ['covered' => 1, 'total' => 12]))
        ->assertSee(__('parking.progress.aggregate_only'))
        ->assertDontSee('AB1234'));
});

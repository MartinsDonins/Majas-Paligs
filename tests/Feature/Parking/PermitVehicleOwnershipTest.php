<?php

declare(strict_types=1);

use App\Enums\MembershipRole;
use App\Enums\PermitType;
use App\Models\Vehicle;
use App\Services\Parking\ParkingActionFailed;
use App\Services\Parking\PermitService;
use App\Services\Parking\VehicleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * MVP-SPEC C.8 Feature/Parking/PermitVehicleOwnershipTest (AC 13): a permit can never reference a vehicle of another
 * apartment (composite FK), and VehiclePolicy limits residents to their own apartment (tenant: own vehicles only).
 */
beforeEach(function (): void {
    $this->b = parkingHouse('owner-house');
    $this->officer = parkingOfficer($this->b);
    $this->carOf2 = activeVehicle($this->b, 2, 'AB1234', $this->officer);
});

it('refuses a permit of apartment 1 bound to the car of apartment 2 — service and raw SQL', function (): void {
    expect(fn () => inBuilding($this->b, fn () => app(PermitService::class)->issue($this->officer, apartment($this->b, 1), 'P-1', PermitType::Main, $this->carOf2, CarbonImmutable::now())))
        ->toThrow(ParkingActionFailed::class, 'vehicle_mismatch');

    expect(fn () => inBuilding($this->b, fn () => DB::transaction(fn () => DB::table('permits')->insert([
        'building_id' => $this->b->id, 'apartment_id' => apartment($this->b, 1)->id, 'number' => 'P-1', 'type' => 'main',
        'vehicle_id' => $this->carOf2->id, 'valid_from' => '2026-10-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]))))->toThrow(QueryException::class);
});

it('lets owners manage all apartment vehicles, tenants only their own, contacts none', function (): void {
    $owner = parkingResident($this->b, 2);
    $tenant = parkingResident($this->b, 2, MembershipRole::Tenant);
    $contact = parkingResident($this->b, 2, MembershipRole::Contact);
    $neighbour = parkingResident($this->b, 3);

    $tenantCar = inBuilding($this->b, function () use ($tenant): Vehicle {
        app(VehicleRegistry::class)->declare($tenant, apartment($this->b, 2), 'TN0001');

        return Vehicle::query()->where('declared_by_user_id', $tenant->id)->firstOrFail();
    });

    inBuilding($this->b, function () use ($owner, $tenant, $contact, $neighbour, $tenantCar): void {
        expect(Gate::forUser($owner)->allows('delete', $this->carOf2))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('delete', $tenantCar))->toBeTrue()
            ->and(Gate::forUser($tenant)->allows('delete', $tenantCar))->toBeTrue()
            ->and(Gate::forUser($tenant)->allows('delete', $this->carOf2))->toBeFalse()
            ->and(Gate::forUser($contact)->allows('declare', [Vehicle::class, apartment($this->b, 2)]))->toBeFalse()
            ->and(Gate::forUser($neighbour)->allows('view', $this->carOf2))->toBeFalse()
            ->and(Gate::forUser($neighbour)->allows('declare', [Vehicle::class, apartment($this->b, 2)]))->toBeFalse()
            ->and(Gate::forUser($neighbour)->allows('reveal', $this->carOf2))->toBeFalse();
    });
});

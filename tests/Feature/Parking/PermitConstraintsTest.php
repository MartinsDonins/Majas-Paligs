<?php

declare(strict_types=1);

use App\Enums\PermitStatus;
use App\Enums\PermitType;
use App\Models\Permit;
use App\Models\Vehicle;
use App\Services\Parking\ParkingActionFailed;
use App\Services\Parking\PermitService;
use App\Services\Parking\VehicleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * MVP-SPEC C.8 Feature/Parking/PermitConstraintsTest — AC 9 (guest not plate-bound, plate change = replacement with
 * history) and AC 10 (overlapping active permits with the same number + type rejected by the DATABASE).
 */
beforeEach(function (): void {
    $this->b = parkingHouse('permit-house');
    $this->officer = parkingOfficer($this->b);
    $this->car = activeVehicle($this->b, 1, 'AB1234', $this->officer);
    $this->from = CarbonImmutable::parse('2026-04-01');
});

function issue($test, string $number, PermitType $type, ?Vehicle $vehicle, int $apt = 1, ?string $to = '2027-03-31'): Permit
{
    return inBuilding($test->b, fn () => app(PermitService::class)->issue($test->officer, apartment($test->b, $apt), $number, $type, $vehicle, $test->from, $to !== null ? CarbonImmutable::parse($to) : null));
}

it('rejects overlapping active permits with the same number and type at the DB level (AC 10)', function (): void {
    issue($this, 'P-001', PermitType::Main, $this->car);

    expect(fn () => issue($this, 'P-001', PermitType::Main, activeVehicle($this->b, 2, 'CD5678', $this->officer), apt: 2))
        ->toThrow(ParkingActionFailed::class, 'overlap');

    // Raw insert bypassing the service still fails (EXCLUDE constraint).
    expect(fn () => inBuilding($this->b, fn () => DB::transaction(fn () => DB::table('permits')->insert([
        'building_id' => $this->b->id, 'apartment_id' => apartment($this->b, 1)->id, 'number' => 'P-001', 'type' => 'main',
        'vehicle_id' => $this->car->id, 'valid_from' => '2026-06-01', 'valid_to' => null, 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]))))->toThrow(QueryException::class);

    // Same number, other type, or a non-overlapping period is fine.
    expect(issue($this, 'P-001', PermitType::Guest, null)->type)->toBe(PermitType::Guest);
});

it('requires a vehicle for an active main permit (CHECK) and never binds guest permits to a plate (AC 9)', function (): void {
    expect(fn () => issue($this, 'P-002', PermitType::Main, null))->toThrow(ParkingActionFailed::class, 'vehicle_required');

    $guest = issue($this, 'V-002', PermitType::Guest, $this->car);
    expect($guest->vehicle_id)->toBeNull();

    expect(fn () => inBuilding($this->b, fn () => DB::transaction(fn () => DB::table('permits')->insert([
        'building_id' => $this->b->id, 'apartment_id' => apartment($this->b, 1)->id, 'number' => 'P-003', 'type' => 'main',
        'vehicle_id' => null, 'valid_from' => '2026-06-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]))))->toThrow(QueryException::class);
});

it('replaces a main permit on a plate change and keeps the history (AC 9)', function (): void {
    $old = issue($this, 'P-010', PermitType::Main, $this->car);
    $resident = parkingResident($this->b, 1);

    // Resident: "Mainīt auto" → pending + task with the permit; officer verifies → replacement.
    inBuilding($this->b, fn () => app(VehicleRegistry::class)->declare($resident, apartment($this->b, 1), 'NEW001', replacing: $old));
    $newCar = inBuilding($this->b, fn () => Vehicle::query()->where('status', 'pending')->firstOrFail());
    inBuilding($this->b, fn () => app(VehicleRegistry::class)->activate($this->officer, $newCar));

    inBuilding($this->b, function () use ($old, $newCar): void {
        $old->refresh();
        $new = Permit::query()->findOrFail($old->replaced_by_permit_id);

        expect($old->status)->toBe(PermitStatus::Returned)
            ->and($new->status)->toBe(PermitStatus::Active)
            ->and($new->number)->toBe('P-010')
            ->and($new->vehicle_id)->toBe($newCar->id)
            ->and($new->valid_to?->toDateString())->toBe('2027-03-31');
    });
});

it('rejects an end date before the start date', function (): void {
    expect(fn () => issue($this, 'P-020', PermitType::Guest, null, to: '2026-01-01'))->toThrow(ParkingActionFailed::class, 'invalid_period');
});

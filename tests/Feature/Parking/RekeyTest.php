<?php

declare(strict_types=1);

use App\Casts\Bytea;
use App\Enums\LookupReason;
use App\Models\ParkingLot;
use App\Models\PlateSightingStat;
use App\Models\Sighting;
use App\Models\Vehicle;
use App\Services\Parking\PlateLookupService;
use App\Services\Parking\SightingService;
use App\Services\Parking\VehicleRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * MVP-SPEC C.8 Feature/Parking/RekeyTest (AC 6): plates:rekey moves a building to the next key version atomically —
 * ciphertexts and blind indexes change, lookups keep working, the active-bidx UNIQUE stays intact, stats are rebuilt;
 * other buildings are untouched. A master rotation works with PLATE_ENC_KEY_PREVIOUS.
 */
beforeEach(function (): void {
    $this->b = parkingHouse('rekey-house');
    $this->other = parkingHouse('rekey-other');
    $this->officer = parkingOfficer($this->b);
    $this->car = activeVehicle($this->b, 1, 'AB1234', $this->officer);
    activeVehicle($this->other, 1, 'AB1234');
    $lot = inBuilding($this->b, fn () => ParkingLot::query()->create(['name' => 'Pagalms']));
    inBuilding($this->b, fn () => app(SightingService::class)->submit(parkingResident($this->b, 2), apartment($this->b, 2), $lot, 'CD5678'));
});

it('re-keys one building atomically and keeps lookups and uniqueness working', function (): void {
    $before = inBuilding($this->b, fn () => Vehicle::query()->findOrFail($this->car->id));
    $otherBefore = inBuilding($this->other, fn () => Vehicle::query()->value('plate_bidx'));

    $this->artisan('plates:rekey', ['building' => 'rekey-house'])->assertSuccessful();

    $after = inBuilding($this->b, fn () => Vehicle::query()->findOrFail($this->car->id));
    expect($this->b->refresh()->settings->plateIndexKeyVersion)->toBe(2)
        ->and($after->plate_key_version)->toBe(2)
        ->and($after->plate_bidx)->not->toBe($before->plate_bidx)
        ->and($after->plate_ciphertext)->not->toBe($before->plate_ciphertext)
        ->and(inBuilding($this->b, fn () => app(VehicleRegistry::class)->plateOf($after)))->toBe('AB1234')
        ->and(inBuilding($this->b, fn () => app(SightingService::class)->plateOf(Sighting::query()->firstOrFail())))->toBe('CD5678')
        ->and($this->other->refresh()->settings->plateIndexKeyVersion)->toBe(1)
        ->and(Bytea::decode(inBuilding($this->other, fn () => Vehicle::query()->value('plate_bidx'))))->toBe(Bytea::decode($otherBefore));

    $resident = parkingResident($this->b, 3);
    expect(inBuilding($this->b, fn () => app(PlateLookupService::class)->lookup($resident, 'ab 1234', LookupReason::OwnCheck)->registered))->toBeTrue();

    // UNIQUE on the active bidx still holds after re-key.
    expect(fn () => inBuilding($this->b, fn () => DB::transaction(fn () => DB::table('vehicles')->insert([
        'id' => '01jzzzzzzzzzzzzzzzzzzzzzzz', 'building_id' => $this->b->id, 'apartment_id' => apartment($this->b, 2)->id,
        'plate_ciphertext' => Bytea::literal('x'), 'plate_bidx' => Bytea::literal($after->plate_bidx), 'plate_key_version' => 2,
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]))))->toThrow(QueryException::class);

    // Stats rebuilt with the new index.
    expect(inBuilding($this->b, fn () => PlateSightingStat::query()->count()))->toBe(1);
});

it('supports a master-key rotation via PLATE_ENC_KEY_PREVIOUS', function (): void {
    $old = config('majas.keys.plate_enc');
    config(['majas.keys.plate_enc' => 'base64:'.base64_encode(str_repeat('r', 32)), 'majas.keys.plate_enc_previous' => $old]);

    $this->artisan('plates:rekey', ['building' => 'rekey-house'])->assertSuccessful();
    config(['majas.keys.plate_enc_previous' => null]);

    $after = inBuilding($this->b, fn () => Vehicle::query()->findOrFail($this->car->id));
    expect(inBuilding($this->b, fn () => app(VehicleRegistry::class)->plateOf($after)))->toBe('AB1234');
});

<?php

declare(strict_types=1);

use App\Casts\Bytea;
use App\Enums\PermitType;
use App\Gdpr\ParkingGateClosed;
use App\Models\Permit;
use App\Models\PermitPlateObservation;
use App\Models\PlateLookup;
use App\Services\Parking\ParkingActionFailed;
use App\Services\Parking\PermitObservationService;
use App\Services\Parking\PermitService;
use App\Services\Parking\PlateRekeyService;
use App\Services\Parking\PlateVault;
use App\Services\Parking\PlateVaultException;
use App\Services\Parking\VaultContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ROUND2-SPEC §12 Feature/Parking/PermitObservationTest (PARK-011, D8): observations exist for guest permits only
 * (service + DB trigger), the plate is sealed (no plaintext, AD bound to the row), no lookup quota is consumed, the
 * gate guard holds below L1 and plates:rekey re-seals them (retention: PermitImportRetentionTest).
 */
beforeEach(function (): void {
    $this->b = parkingHouse('obs-house', level: 1);
    $this->officer = parkingOfficer($this->b, 'obs-officer@example.test');
    $this->actingAs($this->officer);

    [$this->guest, $this->main] = inBuilding($this->b, function (): array {
        $permits = app(PermitService::class);
        $car = activeVehicle($this->b, 2, 'CD5678', $this->officer);

        return [
            $permits->issue($this->officer, apartment($this->b, 1), 'V-1', PermitType::Guest, null, CarbonImmutable::now()),
            $permits->issue($this->officer, apartment($this->b, 2), 'P-1', PermitType::Main, $car, CarbonImmutable::now()),
        ];
    });
});

it('stores the plate only sealed + blind index, guest permits only, without using the lookup quota', function (): void {
    inBuilding($this->b, function (): void {
        $obs = app(PermitObservationService::class)->record($this->officer, $this->guest, 'ab-1234');

        $raw = DB::table('permit_plate_observations')->where('id', $obs->id)->first();
        expect(str_contains((string) Bytea::decode($raw->plate_ciphertext), 'AB1234'))->toBeFalse()
            ->and(Bytea::decode($raw->plate_bidx))->toBe(app(PlateVault::class)->currentBidx($this->b->refresh(), 'AB1234'))
            ->and(app(PermitObservationService::class)->plateOf($obs))->toBe('AB1234')
            ->and(PlateLookup::query()->count())->toBe(0)
            ->and(array_keys($obs->toArray()))->not->toContain('plate_ciphertext')->not->toContain('plate_bidx');

        // AD binds the row: the ciphertext does not open as another row.
        $vault = app(PlateVault::class);
        expect(fn () => $vault->open($obs->plate_ciphertext, new VaultContext((int) $this->b->id, 'permit_plate_observations', strtolower((string) Str::ulid())), 1))
            ->toThrow(PlateVaultException::class);

        expect(fn () => app(PermitObservationService::class)->record($this->officer, $this->main, 'AB1234'))->toThrow(ParkingActionFailed::class, 'not_guest')
            ->and(fn () => app(PermitObservationService::class)->record($this->officer, $this->guest, '!!'))->toThrow(ParkingActionFailed::class, 'invalid_plate');

        // The database refuses an observation of a main permit from any path (trigger).
        expect(fn () => DB::transaction(fn () => DB::table('permit_plate_observations')->insert([
            'id' => strtolower((string) Str::ulid()), 'building_id' => $this->b->id, 'permit_id' => $this->main->id,
            'plate_ciphertext' => Bytea::literal(random_bytes(40)), 'plate_bidx' => Bytea::literal(random_bytes(32)),
            'plate_key_version' => 1, 'observed_on' => now()->toDateString(), 'source' => 'officer',
        ])))->toThrow(QueryException::class);
    });
});

it('is guarded by parking gate L1', function (): void {
    $this->b->forceFill(['settings' => $this->b->settings->with(['dpa_scope' => ['core', 'heating', 'outages']])])->save();

    inBuilding($this->b, function (): void {
        expect(fn () => app(PermitObservationService::class)->record($this->officer, Permit::query()->findOrFail($this->guest->id), 'AB1234'))
            ->toThrow(ParkingGateClosed::class);
    });
});

it('is re-keyed together with vehicles and sightings', function (): void {
    $obs = inBuilding($this->b, fn () => app(PermitObservationService::class)->record($this->officer, $this->guest, 'ZZ0005'));
    $before = Bytea::decode(DB::table('permit_plate_observations')->where('id', $obs->id)->value('plate_bidx'));

    $result = inBuilding($this->b, fn () => app(PlateRekeyService::class)->rekey());
    expect($result['observations'])->toBe(1)->and($result['vehicles'])->toBe(1);

    inBuilding($this->b, function () use ($obs, $before): void {
        $after = PermitPlateObservation::query()->findOrFail($obs->id);
        expect($after->plate_key_version)->toBe(2)
            ->and(Bytea::decode($after->plate_bidx))->not->toBe($before)
            ->and(Bytea::decode($after->plate_bidx))->toBe(app(PlateVault::class)->currentBidx($this->b->refresh(), 'ZZ0005'))
            ->and(app(PermitObservationService::class)->plateOf($after))->toBe('ZZ0005');
    });
});

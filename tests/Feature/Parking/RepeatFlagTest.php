<?php

declare(strict_types=1);

use App\Casts\Bytea;
use App\Enums\SightingStatus;
use App\Models\Building;
use App\Models\ParkingLot;
use App\Models\PlateSightingStat;
use App\Models\Sighting;
use App\Models\User;
use App\Services\Parking\PlateVault;
use App\Services\Parking\SightingService;
use App\Services\Parking\SightingStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * MVP-SPEC C.8 Feature/Parking/RepeatFlagTest: "repeat" only with ≥ 2 independent reporting APARTMENTS in 30 days;
 * one apartment reporting many times is not a repeat; the flag decays after 30 days without sightings. Officer-only.
 */
beforeEach(function (): void {
    $this->b = parkingHouse('repeat-house');
    $this->lot = inBuilding($this->b, fn () => ParkingLot::query()->create(['name' => 'Pagalms']));
    $this->a1 = parkingResident($this->b, 1);
    $this->a1b = parkingResident($this->b, 1);
    $this->a2 = parkingResident($this->b, 2);
});

function repeatOf(Building $b, string $plate): bool
{
    return inBuilding($b, fn () => app(SightingStats::class)->isRepeat(app(PlateVault::class)->currentBidx($b, $plate)));
}

function reportFrom(Building $b, User $u, int $apt, ParkingLot $lot, string $plate): void
{
    inBuilding($b, fn () => app(SightingService::class)->submit($u, apartment($b, $apt), $lot, $plate));
}

it('needs two different reporting apartments', function (): void {
    reportFrom($this->b, $this->a1, 1, $this->lot, 'AB1234');
    reportFrom($this->b, $this->a1b, 1, $this->lot, 'AB1234'); // same apartment, other person
    expect(repeatOf($this->b, 'AB1234'))->toBeFalse();

    reportFrom($this->b, $this->a2, 2, $this->lot, 'AB1234');
    expect(repeatOf($this->b, 'AB1234'))->toBeTrue();
});

it('decays after 30 days without sightings and ignores dismissed reports', function (): void {
    reportFrom($this->b, $this->a1, 1, $this->lot, 'AB1234');
    reportFrom($this->b, $this->a2, 2, $this->lot, 'AB1234');
    expect(repeatOf($this->b, 'AB1234'))->toBeTrue();

    inBuilding($this->b, function (): void {
        DB::table('sightings')->where('building_id', $this->b->id)->update(['seen_at' => CarbonImmutable::now()->subDays(31)]);
        app(SightingStats::class)->rebuild();
        $stat = PlateSightingStat::query()->firstOrFail();
        expect($stat->is_repeat)->toBeFalse()->and($stat->days_seen_60d)->toBe(1);
    });

    reportFrom($this->b, $this->a1, 1, $this->lot, 'CD5678');
    reportFrom($this->b, $this->a2, 2, $this->lot, 'CD5678');
    inBuilding($this->b, function (): void {
        $officer = parkingOfficer($this->b);
        foreach (Sighting::query()->where('plate_bidx', Bytea::literal(app(PlateVault::class)->currentBidx($this->b, 'CD5678')))->get() as $s) {
            app(SightingService::class)->handle($officer, $s, SightingStatus::Dismissed);
        }
    });
    expect(repeatOf($this->b, 'CD5678'))->toBeFalse();
});

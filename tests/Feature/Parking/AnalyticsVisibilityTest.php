<?php

declare(strict_types=1);

use App\Enums\PermitType;
use App\Services\Parking\ParkingAnalytics;
use App\Services\Parking\PermitService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/*
 * MVP-SPEC C.8 Feature/Parking/AnalyticsVisibilityTest (AC 11): per-apartment analytics hidden until
 * parking_rules_adopted_at is set; residents see per-stairwell aggregates only; officer/admin tiles are counts.
 */
beforeEach(function (): void {
    $this->b = parkingHouse('analytics-house');
    $this->officer = parkingOfficer($this->b, 'analytics-officer@example.test');
    $a = activeVehicle($this->b, 5, 'AB1234', $this->officer);
    $c = activeVehicle($this->b, 5, 'CD5678', $this->officer);
    inBuilding($this->b, function () use ($a, $c): void {
        app(PermitService::class)->issue($this->officer, apartment($this->b, 5), 'P-1', PermitType::Main, $a, CarbonImmutable::now());
        app(PermitService::class)->issue($this->officer, apartment($this->b, 5), 'P-2', PermitType::Main, $c, CarbonImmutable::now());
        app(PermitService::class)->issue($this->officer, apartment($this->b, 6), 'V-1', PermitType::Guest, null, CarbonImmutable::now());
    });
});

it('gives officer/admin aggregate tiles only', function (): void {
    $s = inBuilding($this->b, fn () => app(ParkingAnalytics::class)->summary());

    expect($s['apartments_two_main'])->toBe(1)
        ->and($s['permits_distribution'])->toBe(['0' => 22, '1' => 1, '2' => 1, '3+' => 0])
        ->and($s['active_vehicles'])->toBe(2)
        ->and($s['guest_permits'])->toBe(1);
});

it('hides apartment numbers until the house adopted parking rules', function (): void {
    steppedUp($this->officer);
    $this->actingAs($this->officer)->get('/manage/analytics-house/parking-analytics')->assertOk()
        ->assertSee(__('admin.parking.analytics.rules_needed'))
        ->assertDontSee(__('admin.parking.analytics.two_main_list'));
    expect(inBuilding($this->b, fn () => Gate::forUser($this->officer)->allows('parking-analytics-apartments')))->toBeFalse();

    $this->b->forceFill(['settings' => $this->b->settings->with(['parking_rules_adopted_at' => '2026-10-01'])])->save();
    expect(inBuilding($this->b, fn () => Gate::forUser($this->officer)->allows('parking-analytics-apartments')))->toBeTrue()
        ->and(inBuilding($this->b, fn () => app(ParkingAnalytics::class)->perApartment()))->toBe([5]);

    steppedUp($this->officer);
    $this->actingAs($this->officer)->get('/manage/analytics-house/parking-analytics')->assertOk()
        ->assertSee(__('admin.parking.analytics.two_main_list'));
});

it('keeps residents out of the analytics page and shows them per-stairwell rings only', function (): void {
    $resident = parkingResident($this->b, 1);

    expect(inBuilding($this->b, fn () => Gate::forUser($resident)->allows('parking-analytics')))->toBeFalse();
    steppedUp($resident);
    $this->actingAs($resident)->get('/manage/analytics-house/parking-analytics')->assertForbidden();
    $this->actingAs($resident)->get('/b/analytics-house/parking/progress')->assertOk()
        ->assertSee(__('parking.progress.stairwells'))
        ->assertDontSee('AB1234');
});

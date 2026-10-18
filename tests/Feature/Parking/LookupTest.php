<?php

declare(strict_types=1);

use App\Casts\Bytea;
use App\Enums\LookupReason;
use App\Enums\MembershipStatus;
use App\Livewire\Parking\Lookup;
use App\Models\PlateLookup;
use App\Models\Vehicle;
use App\Notifications\LimiterAlert;
use App\Services\Parking\LookupResult;
use App\Services\Parking\PlateLookupService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * MVP-SPEC C.8 Feature/Parking/LookupTest — AC 2 (exact match incl. Cyrillic), AC 3 (yes/no only), AC 4 (quotas,
 * countdown, alerts), count-only alert payloads, every lookup logged.
 */
beforeEach(function (): void {
    $this->b = parkingHouse('lookup-house');
    $this->officer = parkingOfficer($this->b, 'officer@example.test');
    activeVehicle($this->b, 5, 'AB1234', $this->officer);
    $this->resident = parkingResident($this->b, 1);
});

function lookup($test, string $plate, LookupReason $reason = LookupReason::UnknownCar): LookupResult
{
    return inBuilding($test->b, fn () => app(PlateLookupService::class)->lookup($test->resident, $plate, $reason));
}

it('matches the same registered plate however it is typed (AC 2)', function (string $typed): void {
    expect(lookup($this, $typed)->registered)->toBeTrue();
})->with(['ab 1234', 'AB-1234', 'АВ1234', 'ab1234']);

it('never matches partial strings or pending declarations', function (): void {
    expect(lookup($this, 'AB123')->registered)->toBeFalse()
        ->and(lookup($this, 'AB12345')->registered)->toBeFalse()
        ->and(lookup($this, 'B1234')->registered)->toBeFalse();
});

it('answers yes/no only — the payload never contains an apartment, permit or history (AC 3)', function (): void {
    $component = inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(Lookup::class)
        ->call('setReason', 'blocking')
        ->call('check', 'ab-1234')
        ->assertSet('registered', true)
        ->assertSee(__('parking.lookup.registered'))
        ->assertSee(__('parking.lookup.logged')));

    $html = $component->html();
    $snapshot = json_encode($component->snapshot);
    foreach (['AB1234', 'ab-1234', '"5"', 'Dz. 5', __('enum.permit_type.main')] as $needle) {
        expect(str_contains($snapshot, $needle))->toBeFalse("snapshot leaks {$needle}");
    }
    expect($html)->not->toContain('AB1234');

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(Lookup::class)
        ->call('check', 'CD5678')
        ->assertSet('registered', false)
        ->assertSee(__('parking.lookup.not_found'))
        ->assertSee(__('parking.lookup.completeness', ['percent' => 4])));
});

it('logs every lookup in plate_lookups with the bidx (never the plate) and the reason', function (): void {
    lookup($this, 'AB1234', LookupReason::Blocking);
    lookup($this, 'ZZ9999');

    $rows = inBuilding($this->b, fn () => PlateLookup::query()->orderBy('id')->get());
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->result)->toBeTrue()->and($rows[0]->reason_code)->toBe(LookupReason::Blocking)
        ->and($rows[1]->result)->toBeFalse()
        ->and(strlen($rows[0]->plate_bidx))->toBe(32)
        ->and(json_encode($rows->toArray()))->not->toContain('AB1234');
});

it('shows the friendly countdown on the 6th lookup in an hour (AC 4) and enforces the daily quota', function (): void {
    for ($i = 0; $i < 5; $i++) {
        expect(lookup($this, 'AB1234')->ok())->toBeTrue();
    }
    $sixth = lookup($this, 'AB1234');
    expect($sixth->status)->toBe(LookupResult::RATE_LIMITED)->and($sixth->retryAfter)->toBeGreaterThan(3000);

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(Lookup::class)
        ->call('check', 'AB1234')
        ->assertSet('registered', null)
        ->assertSee(__('parking.retry.title')));

    // Day quota: 20 per user — travel past the hour windows.
    for ($h = 1; $h <= 3; $h++) {
        $this->travel(61)->minutes();
        for ($i = 0; $i < 5; $i++) {
            lookup($this, 'CD'.$h.$i);
        }
    }
    $this->travel(61)->minutes();
    expect(lookup($this, 'EF0001')->status)->toBe(LookupResult::RATE_LIMITED);
    expect(inBuilding($this->b, fn () => PlateLookup::query()->count()))->toBe(20);
});

it('enforces the building quota and alerts officer + elders at 50 % and 100 % with counts only', function (): void {
    Notification::fake();
    $this->b->forceFill(['settings' => $this->b->settings->with(['lookup_quota' => ['user_hour' => 100, 'user_day' => 100, 'building_day' => 4]])])->save();

    foreach (['A1', 'A2', 'A3', 'A4'] as $p) {
        expect(lookup($this, $p)->ok())->toBeTrue();
    }
    expect(lookup($this, 'A5')->status)->toBe(LookupResult::RATE_LIMITED);

    $payloads = [];
    Notification::assertSentTo($this->officer, LimiterAlert::class, function (LimiterAlert $n) use (&$payloads): bool {
        $mail = $n->toMail($this->officer);
        $payloads[] = json_encode([$n->toArray($this->officer), $mail->subject, $mail->introLines, $mail->outroLines]);

        return $n->limiter === 'lookup-building';
    });
    expect($payloads)->toHaveCount(2);
    foreach ($payloads as $p) {
        expect($p)->not->toContain('A1')->not->toContain('AB1234')->not->toContain((string) $this->resident->id.'"');
    }
});

it('alerts the officer when one user checks the same plate 3 times a day (count only)', function (): void {
    Notification::fake();

    lookup($this, 'AB1234');
    lookup($this, 'AB1234');
    Notification::assertNothingSentTo($this->officer);
    lookup($this, 'ab-1234');

    Notification::assertSentTo($this->officer, LimiterAlert::class, fn (LimiterAlert $n) => $n->limiter === 'lookup-repeat' && $n->count === 3);
});

it('refuses lookups to pending members and non-residents of the module', function (): void {
    $pending = makeUser();
    member($pending, $this->b, 2, MembershipStatus::Pending);

    inBuilding($this->b, fn () => Livewire::actingAs($pending)->test(Lookup::class)->call('check', 'AB1234')->assertForbidden());
    expect(inBuilding($this->b, fn () => PlateLookup::query()->count()))->toBe(0);
});

it('uses a per-building index: the same plate registered in another house is not "registered" here', function (): void {
    $other = parkingHouse('lookup-other');
    activeVehicle($other, 1, 'XY7777');

    expect(lookup($this, 'XY7777')->registered)->toBeFalse();

    $bidxHere = inBuilding($this->b, fn () => PlateLookup::query()->value('plate_bidx'));
    $bidxThere = inBuilding($other, fn () => Vehicle::query()->value('plate_bidx'));
    expect(Bytea::decode($bidxHere))->not->toBe(Bytea::decode($bidxThere));
});

<?php

declare(strict_types=1);

use App\Auth\PinAuthService;
use App\Enums\BuildingRoleType;
use App\Livewire\Onboarding\RedeemInvite;
use App\Models\Building;
use App\Models\User;
use App\Notifications\LimiterAlert;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/*
 * MVP-SPEC A.12 Unit/PinAuthServiceTest — the service-level rules that Feature/Auth/PinLoginTest does not cover,
 * plus the denial-of-service review fixes (0.0.12): a locked handle or a paused building never feeds the building
 * limiter again; the daily lock is progressive (4 h → 24 h → 72 h) with a count-only elder alert; the platform-wide
 * /join monitor is alert-only and counts only well-formed codes.
 */
beforeEach(fn () => Notification::fake());

function pinResident(Building $b, int $apartment, string $suffix): User
{
    $user = User::query()->create(['locale' => 'lv']);
    $user->forceFill(['login_handle' => $apartment.'-'.$suffix])->save();
    app(PinAuthService::class)->setPin($user, '958372', $apartment);
    member($user, $b, $apartment);

    return $user->refresh();
}

it('normalises handles typed loosely (spaces, lowercase, Cyrillic look-alikes, missing dash)', function (): void {
    expect(PinAuthService::normalizeHandle(' 145 k7mq '))->toBe('145-K7MQ')
        ->and(PinAuthService::normalizeHandle('145К7МQ'))->toBe('145-K7MQ')
        ->and(PinAuthService::normalizeTempCode('ab-cd 23'))->toBe('ABCD23');
});

it('does not count attempts on an already locked handle towards the building limiter', function (): void {
    $b = makeBuilding('pin-dos', 1, 3);
    $victim = pinResident($b, 1, 'LOCK');
    $victim->forceFill(['pin_locked_until' => now()->addHours(4)])->save();

    for ($i = 0; $i < 60; $i++) {
        app(PinAuthService::class)->attempt('1-LOCK', '000001', '10.1.'.intdiv($i, 10).'.'.$i);
    }

    expect(RateLimiter::attempts('pin-building:'.$b->id))->toBe(0)
        ->and(app(PinAuthService::class)->buildingPaused($b))->toBeFalse();

    // Another resident of the same building can still log in.
    $neighbour = pinResident($b, 2, 'GOOD');
    expect(app(PinAuthService::class)->attempt($neighbour->login_handle, '958372', '10.9.9.9')->ok())->toBeTrue();
});

it('locks progressively (4 h, then 24 h) instead of forever and alerts the elders with a count only', function (): void {
    $b = makeBuilding('pin-prog', 1, 2);
    $elder = makeUser('pin-elder@example.test');
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);
    $user = pinResident($b, 3, 'PROG');
    $fail = function (int $n, string $prefix): void {
        for ($i = 0; $i < $n; $i++) {
            $this->travel(16)->minutes(); // past the 5-wrong cooldown
            app(PinAuthService::class)->attempt('3-PROG', '000001', $prefix.$i);
        }
    };

    $fail(10, '198.51.110.');
    $first = $user->refresh()->pin_locked_until;
    expect($first)->not->toBeNull()
        ->and($first->diffInMinutes(now()))->toBeLessThanOrEqual(4 * 60)
        ->and($first->isFuture())->toBeTrue();
    Notification::assertSentTo($elder, LimiterAlert::class, fn (LimiterAlert $n) => $n->limiter === 'pin-handle-lock' && $n->count === 1);

    $this->travel(5)->hours(); // lock over → the right PIN works again
    expect(app(PinAuthService::class)->attempt('3-PROG', '958372', '198.51.111.1')->ok())->toBeTrue();

    $fail(10, '198.51.112.');
    $second = $user->refresh()->pin_locked_until;
    expect($second->diffInHours(now(), true))->toBeGreaterThan(20);
});

it('clears the lock and the lock history on an elder reset', function (): void {
    $b = makeBuilding('pin-reset', 1, 1);
    $user = pinResident($b, 2, 'RSET');
    $user->forceFill(['pin_locked_until' => now()->addDay()])->save();

    $code = app(PinAuthService::class)->issueTempCode($user);

    expect($user->refresh()->pin_locked_until)->toBeNull()
        ->and(app(PinAuthService::class)->attempt('2-RSET', $code, '203.0.113.50')->ok())->toBeTrue();
});

it('never blocks /join on the platform-wide monitor and ignores malformed codes there', function (): void {
    $platform = makeUser('join-alerts@example.test', ['platform_role' => 'platform_admin']);
    // Per-IP limiting is covered in RedeemInviteTest; lift it here to look at the platform-wide monitor alone.
    config(['majas.invites.throttle.global_alert_per_hour' => 3, 'majas.invites.throttle.ip_per_15min' => 100]);
    $building = makeBuilding('join-global', 1, 2, open: true);
    $code = makeInvite($building, 5);

    // Malformed input (too short) is answered locally and not counted.
    for ($i = 0; $i < 5; $i++) {
        Livewire::test(RedeemInvite::class)->set('code', 'AB')->call('submit')->assertSet('cooldown', false);
    }
    expect(RateLimiter::attempts('invite-global:'.now()->format('YmdH')))->toBe(0);

    // Well-formed wrong codes from several IPs pass the threshold: alert only, never a cooldown.
    foreach (['2', '3', '4', '5'] as $i) {
        Livewire::test(RedeemInvite::class)->set('code', 'ABCDE2345'.$i)->call('submit')->assertSet('cooldown', false);
    }
    Notification::assertSentTo($platform, LimiterAlert::class, fn (LimiterAlert $n) => $n->limiter === 'invite-global');

    Livewire::test(RedeemInvite::class)->set('code', $code)->call('submit')->assertRedirect(route('join.role'));
});

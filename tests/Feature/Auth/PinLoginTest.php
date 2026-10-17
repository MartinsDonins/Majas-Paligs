<?php

declare(strict_types=1);

use App\Auth\PinAuthService;
use App\Auth\PinHasher;
use App\Auth\PinLoginResult;
use App\Enums\BuildingRoleType;
use App\Livewire\Auth\PinLogin;
use App\Livewire\Elder\PinReset;
use App\Models\ApartmentMembership;
use App\Models\AuditEntry;
use App\Models\Building;
use App\Models\User;
use App\Notifications\LimiterAlert;
use App\Notifications\PinResetNotice;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function pinUser(Building $b, int $apartment, string $handleSuffix = 'K7MQ', string $pin = '958372', ?string $email = null): User
{
    $user = User::query()->create(['email' => $email, 'locale' => 'lv']);
    $user->forceFill(['login_handle' => $apartment.'-'.$handleSuffix])->save();
    app(PinAuthService::class)->setPin($user, $pin, $apartment);
    member($user, $b, $apartment);

    return $user->refresh();
}

function attempt(string $handle, string $pin, string $ip = '203.0.113.7'): PinLoginResult
{
    return app(PinAuthService::class)->attempt($handle, $pin, $ip);
}

beforeEach(fn () => Notification::fake());

it('logs in with handle + PIN (handle typed loosely)', function (): void {
    $b = makeBuilding('pin-a', 1, 2);
    $user = pinUser($b, 5);

    expect(attempt('5-k7mq', '958372')->user?->id)->toBe($user->id)
        ->and(attempt(' 5 K7MQ ', '958372')->ok())->toBeTrue();
});

it('applies the per-handle cooldown after 5 wrong PINs and the daily lock after 10', function (): void {
    $b = makeBuilding('pin-b', 1, 2);
    $user = pinUser($b, 5);

    for ($i = 0; $i < 5; $i++) {
        attempt('5-K7MQ', '000001', "198.51.100.{$i}");
    }
    expect(attempt('5-K7MQ', '958372', '198.51.100.50')->ok())->toBeFalse();

    $this->travel(16)->minutes();
    expect(attempt('5-K7MQ', '958372', '198.51.100.51')->ok())->toBeTrue();

    for ($i = 0; $i < 10; $i++) {
        $this->travel(16)->minutes();
        attempt('5-K7MQ', '000001', "198.51.101.{$i}");
    }
    expect($user->refresh()->pin_locked_until?->isFuture())->toBeTrue();
    $this->travel(2)->hours();
    expect(attempt('5-K7MQ', '958372', '198.51.102.1')->ok())->toBeFalse();
});

it('blocks the 21st attempt from one IP across 21 different handles (calm cooldown)', function (): void {
    makeBuilding('pin-c', 1, 1);

    for ($i = 0; $i < 20; $i++) {
        expect(attempt("9-AAA{$i}", '000001', '192.0.2.10')->status)->toBe('failed');
    }

    expect(attempt('9-ZZZZ', '000001', '192.0.2.10')->status)->toBe('cooldown');
});

it('treats IPv6 addresses of one /64 as one client', function (): void {
    for ($i = 0; $i < 20; $i++) {
        attempt("9-BBB{$i}", '000001', "2001:db8:1:2::{$i}");
    }

    expect(attempt('9-ZZZZ', '000001', '2001:db8:1:2:ffff::1')->status)->toBe('cooldown')
        ->and(attempt('9-ZZZZ', '000001', '2001:db8:1:3::1')->status)->toBe('failed');
});

it('blocks the 101st failure in a building and alerts elders/admin at 50 and 100', function (): void {
    $b = makeBuilding('pin-d', 1, 9);
    $admin = makeUser('pinadmin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $users = [];
    for ($a = 1; $a <= 21; $a++) {
        $users[] = pinUser($b, $a, 'Q'.str_pad((string) $a, 3, 'X', STR_PAD_LEFT));
    }

    $n = 0;
    foreach ($users as $u) {
        for ($k = 0; $k < 5 && $n < 100; $k++, $n++) {
            attempt($u->login_handle, '000001', '10.0.'.intdiv($n, 15).'.'.$n);
        }
    }

    Notification::assertSentToTimes($admin, LimiterAlert::class, 2);
    $victim = pinUser($b, 30, 'GOOD');
    expect(attempt($victim->login_handle, '958372', '10.9.9.9')->ok())->toBeFalse(); // building paused 15 min

    $this->travel(16)->minutes();
    expect(attempt($victim->login_handle, '958372', '10.9.9.9')->ok())->toBeTrue();
});

it('only alerts (never blocks) on the global monitor', function (): void {
    $admin = makeUser('platform@example.test', ['platform_role' => 'platform_admin']);
    config(['majas.pin.global_failed_alert_per_hour' => 60, 'majas.pin.global_unknown_alert_per_hour' => 50]);
    $b = makeBuilding('pin-e', 1, 1);
    $ok = pinUser($b, 2, 'FINE');

    for ($i = 0; $i < 61; $i++) {
        attempt("77-U{$i}", '000001', '2001:db8:'.dechex($i).'::1');
    }

    Notification::assertSentTo($admin, LimiterAlert::class, fn ($n) => $n->limiter === 'pin-global');
    expect(attempt($ok->login_handle, '958372', '2001:db8:aaaa::1')->ok())->toBeTrue();
});

it('gives byte-identical responses for unknown handle, wrong PIN, locked handle and paused building', function (): void {
    $b = makeBuilding('pin-f', 1, 2);
    pinUser($b, 1, 'WRNG');
    $locked = pinUser($b, 2, 'LOCK');
    $locked->forceFill(['pin_locked_until' => now()->addYear()])->save();
    $c = makeBuilding('pin-g', 1, 1);
    pinUser($c, 1, 'PAUS');
    $c->forceFill(['settings' => $c->settings->with(['pin_paused_until' => now()->addHour()->toIso8601String()])])->save();

    $render = function (string $handle, string $ip): string {
        $html = Livewire::test(PinLogin::class)->set('handle', $handle)->set('pin', '000001')->call('login')->assertSet('failed', true)->html();

        return (string) preg_replace(['/wire:snapshot="[^"]*"/', '/wire:id="[^"]*"/', '/value="[^"]*"/'], '', $html);
    };

    $responses = [
        $render('9-NONE', '203.0.113.1'),
        $render('1-WRNG', '203.0.113.2'),
        $render('2-LOCK', '203.0.113.3'),
        $render('1-PAUS', '203.0.113.4'),
    ];

    expect(array_unique($responses))->toHaveCount(1);
});

it('runs a dummy Argon2id verify for unknown handles', function (): void {
    $spy = Mockery::spy(PinHasher::class)->makePartial();
    app()->instance(PinHasher::class, $spy);

    app(PinAuthService::class)->attempt('9-NOPE', '123123', '203.0.113.9');

    $spy->shouldHaveReceived('dummyVerify')->once();
});

it('rejects weak PINs at set and reset', function (): void {
    $user = makeUser();

    expect(fn () => app(PinAuthService::class)->setPin($user, '123456'))->toThrow(ValidationException::class)
        ->and(fn () => app(PinAuthService::class)->setPin($user, '145145', 145))->toThrow(ValidationException::class);
});

it('lets only the elder of that stairwell reset: single-use 24 h temp code, forced new PIN, audit + notices', function (): void {
    $b = makeBuilding('pin-h', 2, 2);
    $elder = makeUser('elder-pin@example.test');
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);
    $user = pinUser($b, 3, 'RSET', email: 'pinuser@example.test');
    $flatmate = makeUser('flatmate@example.test');
    member($flatmate, $b, 3);
    $membership = inBuilding($b, fn () => ApartmentMembership::query()->where('user_id', $user->id)->firstOrFail());

    $code = inBuilding($b, fn () => Livewire::actingAs($elder)->test(PinReset::class)->call('issue', $membership->id)->get('tempCode'));

    expect($code)->toHaveLength(8);
    Notification::assertSentTo($user, PinResetNotice::class);
    Notification::assertSentTo($flatmate, PinResetNotice::class);
    expect(AuditEntry::query()->forBuilding($b->id)->where('action', 'pin.reset')->first()?->subject_user_ids)->toBe([$user->id]);

    $result = attempt('3-RSET', strtolower(substr($code, 0, 4)).'-'.substr($code, 4));
    expect($result->ok())->toBeTrue()->and($result->mustChangePin)->toBeTrue();
    expect(attempt('3-RSET', $code)->ok())->toBeFalse(); // single use

    // only /login/pin/new is reachable while pin_must_change
    $this->actingAs($user->refresh())->get('/me/privacy')->assertRedirect(route('login.pin.new'));
    $this->actingAs($user)->get('/login/pin/new')->assertOk();
});

it('expires temporary codes after 24 h', function (): void {
    $b = makeBuilding('pin-i', 1, 1);
    $user = pinUser($b, 1, 'XPIR');
    $code = app(PinAuthService::class)->issueTempCode($user);

    $this->travel(25)->hours();
    expect(attempt('1-XPIR', $code)->ok())->toBeFalse();
});

it('refuses resets by an elder of another stairwell and caps 5 per day', function (): void {
    $b = makeBuilding('pin-j', 2, 2);
    $elder2 = makeUser('elder-s2@example.test');
    grantRole($elder2, $b, BuildingRoleType::SectionElder, 2);
    $user = pinUser($b, 1, 'OTHR');
    $membership = inBuilding($b, fn () => ApartmentMembership::query()->where('user_id', $user->id)->firstOrFail());

    inBuilding($b, fn () => Livewire::actingAs($elder2)->test(PinReset::class)->call('issue', $membership->id)->assertForbidden());

    // cap: section 2 apartments 9..16
    $elder2Users = [];
    for ($a = 9; $a <= 14; $a++) {
        $elder2Users[] = pinUser($b, $a, 'CAP'.($a % 10));
    }
    inBuilding($b, function () use ($elder2, $elder2Users): void {
        foreach ($elder2Users as $i => $u) {
            $m = ApartmentMembership::query()->where('user_id', $u->id)->firstOrFail();
            $t = Livewire::actingAs($elder2)->test(PinReset::class)->call('issue', $m->id);
            $i < 5 ? $t->assertHasNoErrors() : $t->assertHasErrors('reset');
        }
    });
});

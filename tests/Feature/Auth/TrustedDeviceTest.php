<?php

declare(strict_types=1);

use App\Auth\RememberCookieGuard;
use App\Auth\SessionEpoch;
use App\Auth\StepUpService;
use App\Auth\TrustedDevices;
use App\Enums\BuildingRoleType;
use App\Enums\PlatformRole;
use App\Livewire\Auth\StepUp;
use App\Livewire\Me\Security;
use App\Models\AuditEntry;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\SecurityChangedNotice;
use App\Platform\BreakGlass;
use App\Services\Memberships\BuildingRoleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\DbRoles;

/*
 * ADR 0006 / AUTH-006: "Uzticēties šai ierīcei 30 dienas" — no step-up on that device for 30 days, never for a
 * dangerous action; bound to the user and the session epoch; audited; and comfortable sessions that respect
 * "Atcerēties šo telefonu".
 */
beforeEach(fn () => Notification::fake());

function totpAdmin(string $email): array
{
    $secret = app(Google2FA::class)->generateSecretKey(32);
    $admin = makeUser($email, ['platform_role' => PlatformRole::PlatformAdmin->value, 'app_authentication_secret' => $secret]);

    return [$admin, $secret];
}

/** The trusted-device cookie value queued by the last response / action. */
function queuedTrustCookie(): ?string
{
    $cookie = Cookie::queued(TrustedDevices::COOKIE);

    return $cookie?->getValue();
}

it('trusts the device after a TOTP step-up with the box ticked and skips the step-up there for 30 days', function (): void {
    [$admin, $secret] = totpAdmin('td-admin@example.test');
    $this->actingAs($admin);
    $this->get('/platform')->assertRedirect(route('step-up'));

    Livewire::test(StepUp::class)
        ->set('trust', true)
        ->set('code', app(Google2FA::class)->getCurrentOtp($secret))
        ->call('verify')
        ->assertHasNoErrors();

    $device = TrustedDevice::query()->sole();
    $cookie = queuedTrustCookie();
    expect($device->user_id)->toBe($admin->id)
        ->and($device->method)->toBe('totp')
        ->and($device->expires_at->isAfter(now()->addDays(29)))->toBeTrue()
        ->and($cookie)->toStartWith($device->id.'|')
        // only the hash of the secret is stored
        ->and($device->token_hash)->toBe(hash('sha256', explode('|', (string) $cookie)[1]));
    expect(Cookie::queued(TrustedDevices::COOKIE)->isSecure())->toBeTrue()
        ->and(Cookie::queued(TrustedDevices::COOKIE)->isHttpOnly())->toBeTrue()
        ->and(Cookie::queued(TrustedDevices::COOKIE)->getDomain())->toBeNull()
        ->and(Cookie::queued(TrustedDevices::COOKIE)->getPath())->toBe('/');
    Notification::assertSentTo($admin, SecurityChangedNotice::class, fn (SecurityChangedNotice $n): bool => $n->kind === 'device_trusted');

    // A new session two weeks later on the same device: straight into the panel, audited once.
    $this->travel(14)->days();
    session()->flush();
    $this->actingAs($admin)->withCookie(TrustedDevices::COOKIE, (string) $cookie)->get('/platform')->assertOk();
    $this->withCookie(TrustedDevices::COOKIE, (string) $cookie)->get('/platform')->assertOk();
    expect(AuditEntry::query()->platformChain()->where('action', 'account.trusted_device_used')->count())->toBe(1)
        ->and(AuditEntry::query()->platformChain()->where('action', 'account.device_trusted')->count())->toBe(1);

    // …but not after 30 days.
    $this->travel(17)->days();
    session()->flush();
    $this->actingAs($admin)->withCookie(TrustedDevices::COOKIE, (string) $cookie)->get('/platform')->assertRedirect(route('step-up'));
});

it('never lets a trusted device satisfy a dangerous action — but one TOTP / passkey of this session does', function (): void {
    [$admin] = totpAdmin('td-danger@example.test');
    $b = makeBuilding('td-house', 1, 1);
    $other = makeUser('td-other@example.test');
    $this->actingAs($admin);
    steppedUp($admin, 'trusted');

    expect(app(StepUpService::class)->hasFreshMfa($admin))->toBeTrue()
        ->and(app(StepUpService::class)->hasFreshStrongMfa($admin))->toBeFalse();

    expect(fn () => BreakGlass::read($admin, $b, 'Atbalsta pieprasījums #42', fn () => true))->toThrow(AuthorizationException::class);
    expect(fn () => inBuilding($b, fn () => app(BuildingRoleService::class)->grant($admin, $other, BuildingRoleType::SectionElder, section($b, 1)->id)))
        ->toThrow(AuthorizationException::class);

    steppedUp($admin, 'passkey');
    expect(BreakGlass::read($admin, $b, 'Atbalsta pieprasījums #42', fn () => true))->toBeTrue();
    inBuilding($b, fn () => app(BuildingRoleService::class)->grant($admin, $other, BuildingRoleType::SectionElder, section($b, 1)->id));
    expect(inBuilding($b, fn () => $other->hasRoleIn($b, BuildingRoleType::SectionElder)))->toBeTrue();
});

it('asks for the strong level on the step-up page and ignores the trusted device there', function (): void {
    [$admin, $secret] = totpAdmin('td-strong@example.test');
    $this->actingAs($admin);
    steppedUp($admin, 'trusted');
    $url = app(StepUpService::class)->ask('strong', '/platform/audit');

    expect($url)->toBe('/step-up');
    Livewire::test(StepUp::class)->assertViewHas('strong', true)->assertNoRedirect()
        ->set('code', app(Google2FA::class)->getCurrentOtp($secret))->call('verify')
        // no passkey yet → the one-time offer first
        ->assertSet('offer', true)
        ->call('later')->assertRedirect('/platform/audit');
});

it('binds a trusted device to its account and session epoch and rejects a forged cookie', function (): void {
    [$admin, $secret] = totpAdmin('td-bind@example.test');
    $this->actingAs($admin);
    steppedUp($admin, 'totp');
    $device = app(TrustedDevices::class)->trust($admin, request(), 'totp');
    $cookie = (string) queuedTrustCookie();
    expect($device)->not->toBeNull();

    // Another account on the same browser: the cookie is ignored.
    [$other] = totpAdmin('td-bind-other@example.test');
    session()->flush();
    $this->actingAs($other)->withCookie(TrustedDevices::COOKIE, $cookie)->get('/platform')->assertRedirect(route('step-up'));

    // A forged secret for the right row id.
    session()->flush();
    $this->actingAs($admin)->withCookie(TrustedDevices::COOKIE, $device->id.'|'.str_repeat('A', 43))->get('/platform')->assertRedirect(route('step-up'));

    // "Aizmirst ierīces" / any credential change bumps the epoch: every trusted device ends.
    SessionEpoch::bump($admin);
    session()->flush();
    $this->actingAs($admin->refresh())->withSession([SessionEpoch::SESSION_KEY => $admin->auth_epoch])
        ->withCookie(TrustedDevices::COOKIE, $cookie)->get('/platform')->assertRedirect(route('step-up'));
});

it('only creates a trusted device right after a real MFA of this session', function (): void {
    [$admin] = totpAdmin('td-mint@example.test');
    $this->actingAs($admin);

    steppedUp($admin, 'trusted');
    expect(app(TrustedDevices::class)->trust($admin, request(), 'totp'))->toBeNull();
    steppedUp($admin, 'email');
    expect(app(TrustedDevices::class)->trust($admin, request(), 'totp'))->toBeNull();

    $this->withSession([StepUpService::SESSION_KEY => ['user' => $admin->id, 'at' => now()->subMinutes(11)->getTimestamp(), 'method' => 'totp']]);
    expect(app(TrustedDevices::class)->trust($admin, request(), 'totp'))->toBeNull();

    steppedUp($admin, 'passkey');
    expect(app(TrustedDevices::class)->trust($admin, request(), 'passkey'))->toBeInstanceOf(TrustedDevice::class)
        ->and(TrustedDevice::query()->count())->toBe(1);
});

it('lists, revokes one and revokes all trusted devices in Ieeja un drošība (audited)', function (): void {
    [$admin] = totpAdmin('td-list@example.test');
    $this->actingAs($admin);
    steppedUp($admin, 'totp');
    $a = app(TrustedDevices::class)->trust($admin, request(), 'totp');
    $b = app(TrustedDevices::class)->trust($admin, request()->duplicate(), 'totp');

    Livewire::test(Security::class)
        ->assertSee(__('me.security.devices_title'))
        ->call('revokeDevice', $a->id)->assertRedirect(route('me.security'));
    expect(TrustedDevice::query()->pluck('id')->all())->toBe([$b->id]);

    Livewire::test(Security::class)->call('revokeAllDevices');
    expect(TrustedDevice::query()->count())->toBe(0)
        ->and(AuditEntry::query()->platformChain()->where('action', 'account.device_untrusted')->count())->toBe(2);
});

it('caps live trusted devices per account', function (): void {
    [$admin] = totpAdmin('td-cap@example.test');
    $this->actingAs($admin);

    for ($i = 0; $i < TrustedDevices::MAX_PER_USER + 3; $i++) {
        steppedUp($admin, 'totp');
        app(TrustedDevices::class)->trust($admin, request(), 'totp');
    }

    expect(TrustedDevice::query()->count())->toBe(TrustedDevices::MAX_PER_USER);
});

it('prunes expired trusted devices as majas_retention (committed fixtures)', function (): void {
    $owner = DbRoles::connect('majas_owner');
    $now = now();
    $userId = (int) $owner->table('users')->insertGetId(['email' => 'td-prune@example.test', 'locale' => 'lv', 'created_at' => $now, 'updated_at' => $now]);

    try {
        $row = fn (string $hash, $expires) => ['user_id' => $userId, 'token_hash' => str_pad($hash, 64, '0'), 'auth_epoch' => 0, 'label' => 'x', 'method' => 'totp', 'expires_at' => $expires, 'created_at' => $now, 'updated_at' => $now];
        $owner->table('trusted_devices')->insert([$row('a', $now->copy()->subDays(2)), $row('b', $now->copy()->addDays(5))]);

        config(['database.connections.pgsql_retention.database' => config('database.connections.pgsql.database')]);
        DB::purge('pgsql_retention');
        $this->artisan('prune:login-tokens')->assertSuccessful();

        expect($owner->table('trusted_devices')->where('user_id', $userId)->pluck('token_hash')->all())->toBe([str_pad('b', 64, '0')]);
    } finally {
        $owner->table('trusted_devices')->where('user_id', $userId)->delete();
        $owner->table('users')->where('id', $userId)->delete();
    }
});

// ---- comfortable sessions ("izmet ārā") ----

/** The Set-Cookie header of the session cookie. */
function sessionCookieHeader(TestResponse $response): string
{
    foreach ($response->headers->all('set-cookie') as $line) {
        if (str_starts_with($line, config('session.cookie').'=')) {
            return $line;
        }
    }

    return '';
}

it('keeps the session of a remembered device for 7 idle days and ends a not-remembered one with the browser', function (): void {
    $plain = sessionCookieHeader($this->get('/login'));
    expect($plain)->not->toBe('')->and(strtolower($plain))->not->toContain('expires=');

    $remembered = sessionCookieHeader($this->withCookie(RememberCookieGuard::COOKIE, 'x')->get('/login'));
    preg_match('/expires=([^;]+)/i', $remembered, $m);
    expect(strtotime($m[1] ?? ''))->toBeGreaterThan(now()->addDays(6)->getTimestamp());
});

it('logs a remembered user back in after the session expired, without losing the trusted step-up', function (): void {
    [$admin, $secret] = totpAdmin('td-comeback@example.test');
    $this->actingAs($admin);
    steppedUp($admin, 'totp');
    app(TrustedDevices::class)->trust($admin, request(), 'totp');
    $trust = (string) queuedTrustCookie();
    $admin->forceFill(['remember_token' => str_repeat('r', 60)])->save();

    /** @var RememberCookieGuard $guard */
    $guard = auth()->guard('web');
    $recaller = $admin->id.'|'.$admin->remember_token.'|'.$guard->hashPasswordForCookie($admin->getAuthPassword());

    // The session is gone (expired in Redis); the device still has both cookies.
    session()->flush();
    auth()->forgetGuards();
    $this->withCookie(RememberCookieGuard::COOKIE, $recaller)->withCookie(TrustedDevices::COOKIE, $trust)->get('/platform')->assertOk();
    $this->assertAuthenticatedAs($admin);
});

it('reloads instead of the English "page expired" dialog on a 419', function (): void {
    expect(file_get_contents(resource_path('js/app.js')))->toContain('status === 419')
        ->and(view('filament.session-expired')->render())->toContain('failure.status === 419');
});

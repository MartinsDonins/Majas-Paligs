<?php

declare(strict_types=1);

use App\Auth\MagicLinkService;
use App\Auth\RememberCookieGuard;
use App\Auth\SessionEpoch;
use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Livewire\Auth\EmailLogin;
use App\Livewire\Auth\StepUp;
use App\Livewire\Me\Security;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Support\SafeReturn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * Production QA 2026-10-20 (ieeja-sesija): "it throws me out", RU toggle landing on /sw.js, admin login chores.
 */
beforeEach(fn () => Notification::fake());

/**
 * The exact cookie value SessionGuard::queueRecallerCookie() writes for $user.
 */
function recallerFor(User $user): string
{
    if ($user->remember_token === null) {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /** @var RememberCookieGuard $guard */
    $guard = Auth::guard('web');

    return $user->id.'|'.$user->remember_token.'|'.$guard->hashPasswordForCookie($user->getAuthPassword());
}

// ---- remember this phone ----

it('logs a passwordless user back in from the remember cookie alone (no session)', function (): void {
    $user = makeUser('remember-back@example.test');
    $cookie = recallerFor($user->refresh());
    Auth::forgetGuards();

    $this->withCookie(RememberCookieGuard::COOKIE, $cookie)->get('/me/security')->assertOk();

    $this->assertAuthenticatedAs($user);
    expect(Auth::guard('web')->viaRemember())->toBeTrue();
});

it('refuses the remember cookie once the session epoch was bumped (token rotated)', function (): void {
    $user = makeUser('remember-bumped@example.test');
    $cookie = recallerFor($user->refresh());
    SessionEpoch::bump($user);
    Auth::forgetGuards();

    $this->withCookie(RememberCookieGuard::COOKIE, $cookie)->get('/me/security')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('sets no remember cookie when the box on the magic-link page is unchecked', function (): void {
    makeUser('confirm-unchecked@example.test');
    $token = null;
    Livewire::test(EmailLogin::class)->set('email', 'confirm-unchecked@example.test')->call('send');
    Notification::assertSentTo(User::query()->where('email', 'confirm-unchecked@example.test')->get(), MagicLoginLink::class, function (MagicLoginLink $n) use (&$token): bool {
        $token = explode('#t=', $n->url())[1];

        return true;
    });

    // The form posts remember=0 (hidden) and no checkbox value when unchecked.
    $response = $this->post('/login/confirm', ['t' => $token, 'remember' => '0'])->assertRedirect();
    expect(collect($response->headers->getCookies())->map->getName()->all())->not->toContain(RememberCookieGuard::COOKIE);
    $this->get('/login/confirm')->assertSee('name="remember" value="0"', false);
});

// ---- LV/RU toggle ----

it('returns the LV/RU toggle to the posted page, never to /sw.js or another host', function (): void {
    $this->get('/sw.js')->assertOk();

    $this->from('/sw.js')->post('/locale/ru', ['return' => '/login/pin'])->assertRedirect('/login/pin');
    $this->from('/sw.js')->post('/locale/ru', ['return' => 'https://evil.example/x'])->assertRedirect('/');
    $this->from('/sw.js')->post('/locale/lv', ['return' => '//evil.example/x'])->assertRedirect('/');
    $this->from('/privacy')->post('/locale/lv')->assertRedirect('/privacy');
    $this->from('/up')->post('/locale/ru', ['return' => '/manifest.webmanifest'])->assertRedirect('/');
});

it('keeps the service worker, manifest and health checks out of the session', function (string $path): void {
    $response = $this->get($path)->assertOk();

    expect(collect($response->headers->getCookies())->map->getName()->all())->not->toContain(config('session.cookie'));
})->with(['/sw.js', '/manifest.webmanifest', '/up', '/up/ip']);

it('posts the current page as the toggle return path', function (): void {
    $this->get('/login/pin')->assertSee('name="return" value="/login/pin"', false);
});

it('validates return paths', function (): void {
    expect(SafeReturn::path('/b/x/water?k=1'))->toBe('/b/x/water?k=1')
        ->and(SafeReturn::path(url('/me/security')))->toBe('/me/security')
        ->and(SafeReturn::path(url('/')))->toBe('/')
        ->and(SafeReturn::path('/sw.js'))->toBeNull()
        ->and(SafeReturn::path('/livewire-abc/update'))->toBeNull()
        ->and(SafeReturn::path('/\\evil.example'))->toBeNull()
        ->and(SafeReturn::path('/a b'))->toBeNull()
        ->and(SafeReturn::path('https://evil.example/me'))->toBeNull()
        ->and(SafeReturn::path('/join#c=SECRET'))->toBe('/join');
});

// ---- language and deep link survive the login ----

it('keeps the language chosen on the login page after the login and saves it to the account', function (): void {
    $user = makeUser('ru-guest@example.test');
    Livewire::test(EmailLogin::class)->set('email', 'ru-guest@example.test')->call('send');
    $code = null;
    Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $n) use (&$code): bool {
        $code = $n->code();

        return true;
    });

    $this->withCookie('mp_locale', 'ru')->post('/login/code', ['email' => 'ru-guest@example.test', 'code' => $code, 'remember' => '0']);

    expect($user->refresh()->locale->value)->toBe('ru');
});

it('returns to the deep link that asked for the login', function (): void {
    $user = makeUser('deep@example.test');
    $this->get('/me/security')->assertRedirect(route('login'));

    app(MagicLinkService::class)->request('deep@example.test', '203.0.113.9');
    $code = null;
    Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $n) use (&$code): bool {
        $code = $n->code();

        return true;
    });

    $this->post('/login/code', ['email' => 'deep@example.test', 'code' => $code])->assertRedirect('/me/security');
});

// ---- step-up: sliding window, return URL, Livewire logout ----

it('keeps a step-up alive while the person works and lets it lapse after 2 h idle or 12 h in total', function (): void {
    $user = makeUser('slide@example.test');
    $this->actingAs($user);
    $stepUp = app(StepUpService::class);
    $stepUp->markFresh($user, 'email');

    for ($i = 0; $i < 5; $i++) {
        $this->travel(100)->minutes();
        expect($stepUp->hasFresh($user))->toBeTrue();
        $stepUp->touch($user);
    }

    $this->travel(121)->minutes();
    expect($stepUp->hasFresh($user))->toBeFalse();
    $stepUp->touch($user); // never revives an expired one
    expect($stepUp->hasFresh($user))->toBeFalse();

    $stepUp->markFresh($user, 'email');
    for ($i = 0; $i < 8; $i++) {
        $this->travel(95)->minutes();
        $stepUp->touch($user);
    }
    expect($stepUp->hasFresh($user))->toBeFalse(); // 760 min > 12 h cap
});

it('slides the step-up on every protected request', function (): void {
    $b = makeBuilding('slide-house', 1, 1, open: true);
    $elder = makeUser('slide-elder@example.test');
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);
    $this->actingAs($elder);
    app(StepUpService::class)->markFresh($elder, 'email');

    $this->travel(110)->minutes();
    $this->get('/b/slide-house/help')->assertOk();
    $this->travel(110)->minutes();
    $this->get('/b/slide-house/help')->assertOk();
});

it('returns from the step-up to an absolute same-origin intended URL', function (): void {
    $user = makeUser('abs@example.test');
    $this->actingAs($user);
    session()->put(StepUpService::INTENDED_KEY, url('/me/security'));
    app(StepUpService::class)->markFresh($user, 'email');

    Livewire::actingAs($user)->test(StepUp::class)->assertRedirect('/me/security');
});

it('sends a TOTP enrolment that needs the e-mail step-up back to /me/security', function (): void {
    $user = makeUser('enrol-return@example.test');
    $this->actingAs($user);

    Livewire::actingAs($user)->test(Security::class)->call('start')->assertRedirect(route('step-up'));
    expect(session(StepUpService::INTENDED_KEY))->toBe('/me/security');
});

it('counts a first TOTP enrolment as the step-up, refuses the same code again and resumes the page that asked', function (): void {
    $b = makeBuilding('enrol-house', 1, 1, open: true);
    $admin = makeUser('enrol-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $this->actingAs($admin);
    $stepUp = app(StepUpService::class);

    // /manage asks for TOTP → the banner page remembers /manage/enrol-house for after the enrolment.
    $this->get('/manage/enrol-house')->assertRedirect(route('step-up'));
    Livewire::actingAs($admin)->test(StepUp::class)->assertSee(__('auth.step_up.mfa_required_building'));

    $stepUp->markFresh($admin, 'email');
    $c = Livewire::actingAs($admin)->test(Security::class)->call('start');
    $code = app(Google2FA::class)->getCurrentOtp((string) session('mp.totp.pending'));
    $c->set('code', $code)->call('confirm')->assertHasNoErrors()->assertRedirect('/manage/enrol-house');

    expect($stepUp->hasFreshMfa($admin->refresh()))->toBeTrue()
        ->and($stepUp->verifyTotp($admin, $code))->toBeFalse()
        ->and($stepUp->totpWasReplayed($admin, $code))->toBeTrue();
});

it('explains a replayed TOTP code instead of calling it wrong', function (): void {
    $google = app(Google2FA::class);
    $secret = $google->generateSecretKey(32);
    $user = makeUser('replay-msg@example.test', ['platform_role' => 'platform_admin']);
    $user->forceFill(['app_authentication_secret' => $secret])->save();
    $this->actingAs($user);
    $code = $google->getCurrentOtp($secret);

    Livewire::actingAs($user)->test(StepUp::class)->set('code', $code)->call('verify')->assertHasNoErrors();
    app(StepUpService::class)->forget();
    Livewire::actingAs($user)->test(StepUp::class)->set('code', $code)->call('verify')
        ->assertHasErrors('code')->assertSee(__('auth.step_up.replayed'));
});

it('answers a Livewire request of an ended session with a redirect to the login, not a 401 modal', function (): void {
    $user = makeUser('epoch-lw@example.test');
    $this->actingAs($user)->withSession([SessionEpoch::SESSION_KEY => 0]);
    $user->forceFill(['auth_epoch' => 1])->save();

    $this->withHeader('X-Livewire', '1')->post('/locale/lv')->assertRedirect(route('login'));
    $this->assertGuest();
});

// ---- per-device limits and their messages ----

it('shows the real remaining wait on the PIN cooldown screen', function (): void {
    expect(__('auth.pin.cooldown_body', ['minutes' => 42]))->toContain('42')
        ->and(__('auth.email.cooldown', ['minutes' => 7]))->toContain('7')
        ->and(__('onboarding.cooldown.body', ['minutes' => 3]))->toContain('3');
});

it('keeps an existing authenticator when majas:platform-admin runs again', function (): void {
    $this->artisan('majas:platform-admin', ['email' => 'owner-rerun@example.test'])->assertSuccessful();
    $secret = User::query()->where('email', 'owner-rerun@example.test')->value('app_authentication_secret');

    $this->artisan('majas:platform-admin', ['email' => 'owner-rerun@example.test'])
        ->expectsOutputToContain('--reset-totp')->assertSuccessful();
    expect(User::query()->where('email', 'owner-rerun@example.test')->first()->app_authentication_secret)->toBe($secret);

    $this->artisan('majas:platform-admin', ['email' => 'owner-rerun@example.test', '--reset-totp' => true])->assertSuccessful();
    $user = User::query()->where('email', 'owner-rerun@example.test')->first();
    expect($user->app_authentication_secret)->not->toBe($secret)
        ->and($user->auth_epoch)->toBe(1);
});

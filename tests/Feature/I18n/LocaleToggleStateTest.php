<?php

declare(strict_types=1);

use App\Auth\LoginCompleter;
use App\Auth\PendingCodeStep;
use App\Http\Middleware\SetLocale;
use App\Livewire\Auth\EmailLogin;
use App\Livewire\Auth\StepUp;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Notifications\StepUpCode;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Karen, round 1 (FIX-007.3 + /join): the LV/RU toggle is a plain POST + full reload. It used to forget that an
 * e-mail code had been sent (the code field vanished, a new code had to be requested), and a first visit always
 * started in LV even on a Russian phone. The /join#c= and /login/confirm#t= fragments are kept in the browser
 * (sessionStorage, resources/js/app.js) — covered by tests/Browser/LocaleToggleSmokeTest.
 */
beforeEach(fn () => Notification::fake());

// ---- first-visit language from Accept-Language ----

it('opens a first visit in the browser language (lv / ru, default lv) and remembers it on the device', function (string $header, string $expected): void {
    $response = $this->withHeader('Accept-Language', $header)->get('/login')->assertOk();

    $response->assertSee('lang="'.$expected.'"', false);
    expect($response->headers->get('Vary'))->toContain('Accept-Language');
    $response->assertCookie(SetLocale::COOKIE, $expected);
})->with([
    'ru' => ['ru', 'ru'],
    'ru-RU with q values' => ['ru-RU,ru;q=0.9,en-US;q=0.8', 'ru'],
    'en first, ru second' => ['en-GB,en;q=0.9,ru;q=0.8', 'ru'],
    'lv-LV' => ['lv-LV,lv;q=0.9,ru;q=0.5', 'lv'],
    'lv before ru' => ['lv,ru;q=0.9', 'lv'],
]);

it('falls back to LV without a supported browser language and sets no device cookie then', function (): void {
    $response = $this->withHeader('Accept-Language', 'de-DE,en;q=0.8')->get('/login')->assertOk();

    $response->assertSee('lang="lv"', false)->assertCookieMissing(SetLocale::COOKIE);
});

it('never lets Accept-Language override the device choice or the account language', function (): void {
    $this->withCookie(SetLocale::COOKIE, 'lv')->withHeader('Accept-Language', 'ru-RU,ru')->get('/login')
        ->assertSee('lang="lv"', false);

    $user = makeUser('locale-account@example.test'); // users.locale = lv
    $this->actingAs($user)->withHeader('Accept-Language', 'ru')->get('/me/security')->assertSee('lang="lv"', false);
});

it('keeps the detected RU through the e-mail code login (users.locale follows the device)', function (): void {
    $user = makeUser('locale-detected@example.test');

    $cookie = $this->withHeader('Accept-Language', 'ru-RU,ru;q=0.9')->get('/login')->getCookie(SetLocale::COOKIE);
    expect($cookie?->getValue())->toBe('ru');

    Livewire::test(EmailLogin::class)->set('email', $user->email)->call('send');
    $code = null;
    Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $n) use (&$code): bool {
        $code = $n->code();

        return true;
    });

    $this->withCookie(SetLocale::COOKIE, 'ru')->post('/login/code', ['email' => $user->email, 'code' => $code])->assertRedirect();
    expect($user->refresh()->locale->value)->toBe('ru');
});

// ---- the e-mail code step survives the reload ----

it('stays on the code step after the language toggle reloaded /login', function (): void {
    makeUser('pending-login@example.test');

    Livewire::test(EmailLogin::class)->set('email', 'Pending-Login@example.test')->set('remember', false)->call('send')->assertSet('sent', true);

    // The toggle: POST /locale/ru → back to /login (a fresh component, same session).
    $this->post('/locale/ru', ['return' => '/login'])->assertRedirect('/login');
    $page = $this->withCookie(SetLocale::COOKIE, 'ru')->get('/login')->assertOk();

    $page->assertSee(trans('auth.email.sent_title', [], 'ru'))
        ->assertSee('name="code"', false)
        ->assertSee('value="pending-login@example.test"', false)
        ->assertSee('name="remember" value="0"', false)
        ->assertDontSee(trans('auth.email.send', [], 'ru'));

    Livewire::test(EmailLogin::class)->assertSet('sent', true)->assertSet('email', 'pending-login@example.test')->assertSet('remember', false);
    Notification::assertSentTimes(MagicLoginLink::class, 1);
});

it('forgets the pending code step on "another address", after login and once the code expired', function (): void {
    $user = makeUser('pending-forget@example.test');

    Livewire::test(EmailLogin::class)->set('email', $user->email)->call('send')->call('again')->assertSet('sent', false);
    Livewire::test(EmailLogin::class)->assertSet('sent', false);

    Livewire::test(EmailLogin::class)->set('email', $user->email)->call('send');
    $this->travel((int) config('majas.magic_link.ttl_minutes') + 1)->minutes();
    Livewire::test(EmailLogin::class)->assertSet('sent', false);
    $this->travelBack();

    PendingCodeStep::rememberLogin($user->email, true);
    app(LoginCompleter::class)->login(request(), $user, false, 'email_code');
    expect(PendingCodeStep::login())->toBeNull();
});

it('stays on the e-mail step-up code step after the language toggle and clears it once confirmed', function (): void {
    $elder = makeUser('pending-stepup@example.test');

    Livewire::actingAs($elder)->test(StepUp::class)->call('sendCode')->assertSet('sent', true);
    $code = null;
    Notification::assertSentTo($elder, StepUpCode::class, function (StepUpCode $n) use (&$code): bool {
        $code = $n->code();

        return true;
    });

    // Reload (toggle): a fresh component is still on the code step — no second code mail.
    Livewire::actingAs($elder)->test(StepUp::class)->assertSet('sent', true)->assertSee('name="code"', false);
    Notification::assertSentTimes(StepUpCode::class, 1);

    // Another account in the same browser session never inherits it.
    Livewire::actingAs(makeUser('other-stepup@example.test'))->test(StepUp::class)->assertSet('sent', false);

    Livewire::actingAs($elder)->test(StepUp::class)->set('code', (string) $code)->call('verify');
    expect(PendingCodeStep::stepUp($elder))->toBeFalse();
});

it('marks the fields the toggle keeps in the browser (/join code, link token, e-mail code)', function (): void {
    makeUser('keep-fields@example.test');

    $this->get('/join')->assertOk()->assertSee('data-target="code" data-livewire="true" data-locale-keep', false);
    $this->get('/login/confirm')->assertOk()->assertSee('data-param="t" data-target="t" data-locale-keep', false);

    Livewire::test(EmailLogin::class)->set('email', 'keep-fields@example.test')->call('send')
        ->assertSeeHtml('x-data="keepOnLocale" data-locale-keep data-target="code"');
});

it('refuses an empty or future-dated pending login state', function (): void {
    expect(PendingCodeStep::login())->toBeNull();
    session()->put(PendingCodeStep::LOGIN_KEY, ['email' => '', 'at' => now()->getTimestamp()]);
    expect(PendingCodeStep::login())->toBeNull();
    session()->put(PendingCodeStep::LOGIN_KEY, ['email' => 'x@example.test', 'at' => now()->addMinute()->getTimestamp()]);
    expect(PendingCodeStep::login())->toBeNull(); // a timestamp from the future is refused
    expect(User::query()->count())->toBe(0);
});

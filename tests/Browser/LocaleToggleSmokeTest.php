<?php

declare(strict_types=1);

use App\Auth\MagicLinkService;
use App\Notifications\MagicLoginLink;
use Illuminate\Support\Facades\Notification;

/*
 * Production QA 2026-10-20 ("RU does not work"): in a real browser the service worker fetches /sw.js with the
 * session cookie, which used to become the session's previous URL — the LV/RU toggle then landed on the raw
 * JavaScript. And the language chosen on the login page switched back to LV at the moment of login.
 */
it('returns the LV/RU toggle to the same page even right after a /sw.js fetch', function (): void {
    $page = phone('/login/pin');
    $page->assertSee(__('auth.pin.title'));

    // Exactly what the browser's service-worker update check does.
    $page->script("fetch('/sw.js', {credentials: 'same-origin'}).then(r => r.text())");
    waitUntil($page, "performance.getEntriesByName(location.origin + '/sw.js').length > 0");

    $page->press('RU');
    waitUntil($page, "document.documentElement.lang === 'ru'");
    $page->assertPathIs('/login/pin')->assertNoJavaScriptErrors();

    $page->press('LV');
    waitUntil($page, "document.documentElement.lang === 'lv'");
    $page->assertPathIs('/login/pin');
});

it('keeps the language chosen on the login page after logging in with the e-mail code', function (): void {
    useMailpit();
    $b = makeBuilding('ru-login', 1, 1, open: true);
    $email = browserEmail('ru-login');
    $user = makeUser($email); // users.locale = lv (the default for every account)
    member($user, $b, 1);

    $page = phone('/login');
    $page->press('RU');
    // The reloaded page must be booted before typing: typed while the document is still loading, the value can be
    // replaced when Livewire binds wire:model (a test-speed race — no person types within milliseconds of a reload).
    waitUntil($page, "document.documentElement.lang === 'ru' && document.readyState === 'complete' && !!window.Livewire && Livewire.all().length > 0");

    $page->type('email', $email)
        ->press(trans('auth.email.send', [], 'ru'))
        ->waitForText(trans('auth.email.sent_title', [], 'ru'));
    $page->type('code', mailpitCode($email))
        ->press(trans('auth.login', [], 'ru'));
    waitUntil($page, "location.pathname === '/b/ru-login'", 10);

    expect($page->script('document.documentElement.lang'))->toBe('ru')
        ->and($user->refresh()->locale->value)->toBe('ru');
    $page->assertNoJavaScriptErrors();
});

/*
 * Karen, round 1: a Russian-speaking resident opens the QR link /join#c=CODE (the fragment is cleared at once) and
 * switches to RU — the code was gone. Toggling during the e-mail code step lost the code field (FIX-007.3).
 */
it('keeps the invite code from the QR link across the LV/RU toggle', function (): void {
    $b = makeBuilding('ru-join', 1, 1, open: true);
    $code = makeInvite($b, 2);

    $page = openWithFragment('/join#c='.$code);
    waitUntil($page, "document.querySelector('#code').value !== '' && location.hash === ''");

    $page->press('RU');
    waitUntil($page, "document.documentElement.lang === 'ru' && document.readyState === 'complete' && !!window.Livewire && Livewire.all().length > 0");
    waitUntil($page, 'document.querySelector("#code").value === '.json_encode($code));
    expect($page->script('location.hash'))->toBe('')
        ->and($page->script("Object.keys(sessionStorage).filter(k => k.startsWith('mp-keep:')).length"))->toBe(0);

    $page->press(trans('common.continue', [], 'ru'))->waitForText(trans('onboarding.role.title', [], 'ru'));
    $page->assertPathIs('/join/role')->assertNoJavaScriptErrors();
});

it('keeps the e-mail code step (and a half-typed code) across the LV/RU toggle', function (): void {
    useMailpit();
    $b = makeBuilding('ru-code', 1, 1, open: true);
    $email = browserEmail('ru-code');
    member(makeUser($email), $b, 1);

    $page = phone('/login');
    $page->type('email', $email)->press(__('auth.email.send'))->waitForText(__('auth.email.sent_title'));
    $code = mailpitCode($email);
    $page->type('code', substr($code, 0, 3));

    $page->press('RU');
    waitUntil($page, "document.documentElement.lang === 'ru' && document.readyState === 'complete'");
    $page->assertSee(trans('auth.email.sent_title', [], 'ru'));
    waitUntil($page, 'document.querySelector("#code") && document.querySelector("#code").value === '.json_encode(substr($code, 0, 3)));

    $page->type('code', $code)->press(trans('auth.login', [], 'ru'));
    waitUntil($page, "location.pathname === '/b/ru-code'");
    expect($page->script('document.documentElement.lang'))->toBe('ru');
    $page->assertNoJavaScriptErrors();
});

it('keeps the magic-link token across the LV/RU toggle on /login/confirm', function (): void {
    Notification::fake();
    $b = makeBuilding('ru-link', 1, 1, open: true);
    $user = makeUser(browserEmail('ru-link'));
    member($user, $b, 1);
    app(MagicLinkService::class)->issueFor($user, null);
    $url = null;
    Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $n) use (&$url): bool {
        $url = $n->url();

        return true;
    });
    $path = (string) parse_url((string) $url, PHP_URL_PATH).'#'.parse_url((string) $url, PHP_URL_FRAGMENT);

    $page = openWithFragment($path);
    waitUntil($page, "document.querySelector('input[name=t]').value !== '' && location.hash === ''");

    $page->press('RU');
    waitUntil($page, "document.documentElement.lang === 'ru' && document.readyState === 'complete'");
    waitUntil($page, "document.querySelector('input[name=t]').value !== ''");

    $page->press(trans('auth.login', [], 'ru'));
    waitUntil($page, "location.pathname === '/b/ru-link'");
    $page->assertNoJavaScriptErrors();
});

it('opens a first visit from a Russian phone in RU, and from any other phone in LV', function (): void {
    $ru = phone('/', locale: 'ru-RU');
    expect($ru->script('document.documentElement.lang'))->toBe('ru');
    $ru->assertSee(trans('landing.title', [], 'ru'));

    $other = phone('/', locale: 'de-DE');
    expect($other->script('document.documentElement.lang'))->toBe('lv');
});

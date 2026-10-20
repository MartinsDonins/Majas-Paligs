<?php

declare(strict_types=1);

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
    waitUntil($page, "document.documentElement.lang === 'ru'");

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

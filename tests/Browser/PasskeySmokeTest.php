<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Models\TrustedDevice;

/*
 * ADR 0006 ("admin login is too complicated"): the whole passkey journey in a real Chromium with a virtual platform
 * authenticator (fingerprint / Face ID stand-in) — the real Vite build, @simplewebauthn/browser, the CSP Alpine
 * components and Livewire round trips. Served from `localhost` because WebAuthn refuses an IP address as the RP ID.
 * Synthetic data only.
 */
it('offers a passkey after an admin e-mail login, then signs in and opens the house panel with one tap', function (): void {
    useMailpit();
    $b = makeBuilding('pk-smoke', 1, 1, open: true);
    $email = browserEmail('pk-admin');
    $admin = makeUser($email);
    member($admin, $b, 1);
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    $page = phone('/login', 'localhost')->assertPathIs('/login');
    virtualAuthenticator($page);

    // The passkey button is there (WebAuthn available) next to the e-mail form; no CSP / JS errors from the autofill.
    waitUntil($page, "!!document.querySelector('[data-testid=\"passkey-button\"]') && document.querySelector('[data-testid=\"passkey-button\"]').offsetParent !== null");
    $page->assertSee(__('auth.passkey.login'))->assertNoJavaScriptErrors();

    // 1. The first login of an administrator is still the e-mail code …
    $page->type('email', $email)
        ->press(__('auth.email.send'))
        ->waitForText(__('auth.email.sent_title'));
    $page->type('code', mailpitCode($email))
        ->press(__('auth.login'));

    // 2. … and is followed by the one-time passkey offer (no authenticator app needed).
    waitUntil($page, "location.pathname === '/me/passkey'", 10);
    $page->assertSee(__('me.passkey_offer.title'))->assertNoJavaScriptErrors();
    $page->check('[data-testid="trust-device"]');
    $page->click('[data-testid="passkey-register"]');
    waitUntil($page, "location.pathname !== '/me/passkey'", 15);

    expect($admin->passkeys()->count())->toBe(1)
        ->and(TrustedDevice::query()->where('user_id', $admin->id)->where('method', 'passkey')->count())->toBe(1);

    // The registration counted as this session's MFA: the house panel opens without any code.
    openPath($page, "/admin/b/{$b->slug}");
    waitUntil($page, "location.pathname.startsWith('/admin/b/') || location.pathname === '/step-up'", 10);
    expect($page->script('location.pathname'))->toStartWith('/admin/b/');

    // 3. Log out; next time a single tap on "Ieiet ar pirkstu / Face ID" is the whole login (MFA included).
    openPath($page, "/b/{$b->slug}/me");
    $page->script("document.querySelector('form[action$=\"/logout\"] button').click()");
    waitUntil($page, "location.pathname === '/' || location.pathname === '/login'", 10);
    openPath($page, '/login');
    // Chromium may already answer the autofill (conditional UI) request with the virtual authenticator — then the
    // page signs in and leaves /login before the button can be seen (that race made this test flaky); otherwise
    // the button does it.
    waitUntil($page, "location.pathname !== '/login' || (!!document.querySelector('[data-testid=\"passkey-button\"]') && document.querySelector('[data-testid=\"passkey-button\"]').offsetParent !== null)", 15);
    try {
        $page->script("location.pathname === '/login' && document.querySelector('[data-testid=\"passkey-button\"]').click()");
    } catch (Throwable) {
        // The autofill login was already navigating away.
    }
    waitUntil($page, "location.pathname !== '/login'", 15);
    expect($page->script('location.pathname'))->not->toBe('/me/passkey');

    openPath($page, "/admin/b/{$b->slug}");
    waitUntil($page, "location.pathname.startsWith('/admin/b/') || location.pathname === '/step-up'", 10);
    expect($page->script('location.pathname'))->toStartWith('/admin/b/');

    expect($admin->passkeys()->first()?->last_used_at)->not->toBeNull();
});

it('shows passkeys, trusted devices and the authenticator app in Ieeja un drošība without CSP errors', function (): void {
    useMailpit();
    $b = makeBuilding('pk-sec', 1, 1, open: true);
    $email = browserEmail('pk-resident');
    $resident = makeUser($email);
    member($resident, $b, 1);

    $page = phone('/login', 'localhost')->assertPathIs('/login');
    virtualAuthenticator($page);
    $page->type('email', $email)
        ->press(__('auth.email.send'))
        ->waitForText(__('auth.email.sent_title'));
    $page->type('code', mailpitCode($email))
        ->press(__('auth.login'));
    waitUntil($page, "location.pathname.startsWith('/b/')", 10);

    // A resident (no admin role) is not pushed to the offer but can add a passkey right after the login.
    openPath($page, '/me/security');
    $page->assertSee(__('me.security.passkeys_title'))
        ->assertSee(__('me.security.devices_title'))
        ->assertSee(__('me.security.totp_title'))
        ->assertNoJavaScriptErrors();
    $page->click('[data-testid="passkey-register"]');
    waitUntil($page, 'document.body.innerText.includes('.json_encode(__('me.security.status.passkey_added')).')', 15);
    expect($resident->passkeys()->count())->toBe(1);
    $page->assertNoJavaScriptErrors();
});

/**
 * Same-origin navigation: the plugin's navigate() resolves paths against 127.0.0.1, where WebAuthn (RP ID
 * `localhost`) cannot work.
 */
function openPath(object $page, string $path): void
{
    $page->script('window.__mpNav = true; location.assign('.json_encode($path).')');
    $deadline = microtime(true) + 15;
    while (true) {
        try {
            if ($page->script("!window.__mpNav && document.readyState === 'complete'")) {
                usleep(300_000); // Alpine / Livewire boot

                return;
            }
        } catch (Throwable) {
            // The old document was torn down mid-call; ask the new one.
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Timed out opening {$path}");
        }
        usleep(100_000);
    }
}

<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Models\BuildingRole;

/*
 * FIRSTRUN-001 in a real Chromium at phone width — the owner's own first run: a platform_admin with no house role and
 * no apartment signs in by e-mail, adds a passkey (this session's second factor) and must never meet a blank page:
 * /home → "Jums vēl nav piesaistīta dzīvokļa" → the house page ("Jūsu loma: nav", opening 1 of 4) → "Kļūt par šīs
 * mājas administratoru" → the house panel with "Sākt šeit" → "Iedzīvotāja skats" (same house: closed-house banner and
 * locked module cards) → "Admin skats" back. No sideways scroll at 320 px, no JavaScript / CSP errors.
 * Synthetic data only.
 */
it('walks a platform admin from an empty start to his house panel and back through the view switch', function (): void {
    useMailpit();
    $b = makeBuilding('firstrun-smoke', 2, 1);
    $email = browserEmail('firstrun-owner');
    $owner = makeUser($email, ['platform_role' => 'platform_admin']);

    $page = visit('/login')->withHost('localhost')->on()->mobile()->assertPathIs('/login');
    virtualAuthenticator($page);
    $page->type('email', $email)->press(__('auth.email.send'))->waitForText(__('auth.email.sent_title'));
    $page->type('code', mailpitCode($email))->press(__('auth.login'));
    waitUntil($page, "location.pathname === '/me/passkey'", 10);
    $page->click('[data-testid="passkey-register"]');
    waitUntil($page, "location.pathname !== '/me/passkey'", 15);

    // /home: no longer the empty platform dashboard.
    openPath($page, '/home');
    waitUntil($page, "location.pathname === '/start'", 10);
    $page->assertSee(__('start.no_home.title'))->assertNoJavaScriptErrors();
    $page->resize(320, 720);
    assertNoHorizontalScroll($page, 'start');

    // The house page explains why it is empty and offers the next step.
    $page->click('[data-start-house="firstrun-smoke"]');
    waitUntil($page, "location.pathname === '/admin/houses/firstrun-smoke'", 10);
    $page->assertSee(__('start.house.no_role'))
        ->assertSee(__('start.house.core_progress', ['done' => 1, 'total' => 4]))
        ->assertPresent('[data-testid="view-switch"][data-view="admin"]')
        ->assertNoJavaScriptErrors();
    assertNoHorizontalScroll($page, 'house page');

    $page->click('[data-testid="house-claim"]');
    waitUntil($page, "!!document.querySelector('[data-testid=\"house-panel\"]')", 15);
    expect(inBuilding($b, fn () => BuildingRole::query()->where('user_id', $owner->id)->where('role', BuildingRoleType::BuildingAdmin->value)->exists()))->toBeTrue();

    // The house panel opens with "Sākt šeit".
    $page->click('[data-testid="house-panel"]');
    waitUntil($page, "location.pathname === '/admin/b/firstrun-smoke'", 15);
    waitUntil($page, "!!document.querySelector('[data-testid=\"getting-started\"]')", 10);
    $page->assertPresent('[data-testid="step-opening"][data-status="todo"]')
        ->assertPresent('[data-testid="panel-view-switch"]')
        ->assertNoJavaScriptErrors();

    // "Iedzīvotāja skats" keeps the house: a closed house says so, every module has a locked card.
    $page->click('[data-testid="panel-view-switch"] [data-view-target="resident"]');
    waitUntil($page, "location.pathname === '/b/firstrun-smoke'", 15);
    $page->assertPresent('[data-testid="house-closed"]')
        ->assertPresent('[data-testid="tile-locked-heating"]')
        ->assertPresent('[data-testid="view-switch"][data-view="resident"]')
        ->assertDontSee(__('home.status_calm'))
        ->assertNoJavaScriptErrors();
    assertNoHorizontalScroll($page, 'resident home of a closed house');

    // … and "Admin skats" goes back to the same house panel.
    $page->click('[data-testid="view-switch"] [data-view-target="admin"]');
    waitUntil($page, "location.pathname === '/admin/b/firstrun-smoke'", 15);
});

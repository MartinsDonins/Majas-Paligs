<?php

declare(strict_types=1);

/*
 * Karen, round 1: every wire:navigate logged a CSP `style-src-elem` violation — Livewire 4 adds a <style> element
 * without the page nonce for its view transitions. The plugin normally opens pages with bypassCSP; here Chromium
 * ENFORCES the app's real policy, as on a phone.
 */
it('switches Siltums ↔ Ūdens with wire:navigate without a single CSP violation', function (): void {
    $b = heatingHouse('csp-nav', 1, 3);
    $resident = makeUser();
    member($resident, $b, 1);
    $this->actingAs($resident);

    $page = phone('/b/csp-nav/heating', enforceCsp: true, warm: true);
    recordCspViolations($page);

    $page->click('a[href*="/b/csp-nav/water"]');
    waitUntil($page, "location.pathname === '/b/csp-nav/water' && !document.documentElement.classList.contains('is-navigating')");
    $page->assertSee(__('outage.title'));

    $page->click('a[href*="/b/csp-nav/heating"][wire\:navigate]');
    waitUntil($page, "location.pathname === '/b/csp-nav/heating' && !document.documentElement.classList.contains('is-navigating')");
    $page->assertSee(__('heating.title'));
    $page->wait(0.5);

    expect(cspViolations($page))->toBe([])
        ->and($page->script('window.__mpCsp'))->each->not->toContain('WITHOUT nonce');
    $page->assertNoJavaScriptErrors();
});

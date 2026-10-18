<?php

declare(strict_types=1);

use App\Models\ParkingLot;
use Database\Seeders\LegalNoticeSeeder;

/*
 * MVP-SPEC A.11 AC 16 (layout half; the Lighthouse half is scripts/lighthouse/audit.mjs): every resident screen in
 * LV and RU at 320px, in senior mode (html[data-density=large]) and with the root text at 200 % — no sideways
 * scroll, no JavaScript error, no serious/critical axe-core finding. Full-page screenshots for review go to
 * tests/Browser/Screenshots (git-ignored).
 */
beforeEach(function (): void {
    (new LegalNoticeSeeder)->run();
    $this->b = parkingHouse('layout-house');
    $this->b->forceFill(['settings' => $this->b->settings->with(['modules' => ['heating' => true, 'outages' => true, 'parking' => true]])])->save();
    inBuilding($this->b, fn () => ParkingLot::query()->create(['name' => 'Pagalms']));
    $this->resident = parkingResident($this->b, 1);
    residentReports($this->b, 2, 'cold');
    residentReports($this->b, 6, 'cold');
    residentReports($this->b, 3, 'overheated');
});

dataset('screens', [
    'landing' => ['/', false],
    'join' => ['/join', false],
    'login' => ['/login', false],
    'pin login' => ['/login/pin', false],
    'home' => ['/b/layout-house', true],
    'apartment' => ['/b/layout-house/me', true],
    'heating' => ['/b/layout-house/heating', true],
    'water' => ['/b/layout-house/water', true],
    'parking lookup' => ['/b/layout-house/parking', true],
    'my permits' => ['/b/layout-house/parking/mine', true],
    'sighting' => ['/b/layout-house/parking/sighting', true],
    'privacy' => ['/me/privacy', true],
]);

dataset('modes', [
    'lv 320' => ['lv', 'normal', 100],
    'ru 320 senior' => ['ru', 'large', 100],
    'lv 320 at 200 %' => ['lv', 'normal', 200],
    'ru 320 senior at 200 %' => ['ru', 'large', 200],
]);

it('lays out at 320px without sideways scroll or a11y violations', function (string $path, bool $auth, string $locale, string $density, int $scale): void {
    if ($auth) {
        $this->resident->forceFill(['locale' => $locale, 'text_size' => $density])->save();
        $this->actingAs($this->resident->refresh());
    } else {
        $this->withCookies(['mp_text_size' => $density, 'mp_locale' => $locale]);
    }

    $page = phone($path);
    $page->resize(320, 720);
    $page->script('document.querySelectorAll("details").forEach(d => { d.open = true; })');
    if ($scale !== 100) {
        scaleRootText($page, $scale);
    }
    $page->wait(0.3);

    $page->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.lang'))->toBe($locale);
    if ($auth) {
        expect($page->script('document.documentElement.dataset.density'))->toBe($density);
    }
    assertNoHorizontalScroll($page, "{$path} [{$locale}, {$density}, {$scale} %]");
    if ($scale === 100) {
        $page->assertNoAccessibilityIssues();
    }
    $page->screenshot(filename: 'layout-'.trim(str_replace('/', '-', $path), '-').'-'.$locale.'-'.$density.'-'.$scale);
})->with('screens')->with('modes');

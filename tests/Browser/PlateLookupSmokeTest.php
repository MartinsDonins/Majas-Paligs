<?php

declare(strict_types=1);

use App\Enums\LookupReason;
use App\Models\ParkingLot;
use App\Models\Sighting;
use App\Services\Parking\PlateLookupService;
use App\Services\Parking\SightingService;
use Database\Seeders\LegalNoticeSeeder;

/*
 * MVP-SPEC C.8 "Browser/PlateLookupSmokeTest" — Package C in real Chromium (real Vite build, CSP Livewire/Alpine):
 * the plate travels as an action argument and never sits in a Livewire snapshot, the yes/no result, the offline
 * state, "Ziņot par nezināmu auto" with a photo decoded and re-encoded to JPEG in the browser canvas (and the
 * friendly message for a format the browser cannot decode — HEIC outside iOS Safari), and the live rate-limit
 * countdown on the 6th lookup in an hour. Synthetic plates only (AB1234, ZZ0001, …).
 */
beforeEach(function (): void {
    (new LegalNoticeSeeder)->run();
    $this->b = parkingHouse('park-house');
    $this->officer = parkingOfficer($this->b);
    $this->resident = parkingResident($this->b, 1);
    inBuilding($this->b, fn () => ParkingLot::query()->create(['name' => 'Pagalms']));
    activeVehicle($this->b, 9, 'AB1234', $this->officer);
});

function snapshotsContain(object $page, string $needle): bool
{
    return (bool) $page->script('[...document.querySelectorAll("[wire\\\\:snapshot]")].some(e => e.getAttribute("wire:snapshot").toUpperCase().replace(/[\\s-]/g, "").includes("'.$needle.'"))');
}

it('checks plates, reports an unknown car with a canvas-converted photo and shows the live countdown', function (): void {
    $png = storage_path('framework/testing-sighting.png');
    $image = imagecreatetruecolor(2400, 1200); // > 1600 px: the canvas downsizes it
    imagefilledrectangle($image, 0, 0, 2399, 1199, (int) imagecolorallocate($image, 30, 120, 180));
    imagepng($image, $png);
    $heic = storage_path('framework/testing-sighting.heic');
    file_put_contents($heic, "\x00\x00\x00\x18ftypheic".random_bytes(512)); // a HEIC header Chromium cannot decode

    $this->actingAs($this->resident);
    $page = phone('/b/park-house/parking');
    $page->waitForText(__('parking.lookup.question'));

    // Offline: the banner appears and "Pārbaudīt" is disabled; back online it works.
    browserOffline($page, true);
    $page->waitForText(__('shell.offline'));
    expect($page->script('getComputedStyle(document.querySelector("form button[type=submit][data-requires-online]")).pointerEvents'))->toBe('none');
    browserOffline($page, false);
    waitUntil($page, '!document.documentElement.hasAttribute("data-offline")');

    // 1. Unknown plate → "Nav atrasts reģistrā"; the typed plate never reaches a snapshot.
    $page->type('lookup-plate', 'zz 0001')
        ->click('form[data-method=check] button[type=submit]') // not the "Pārbaudīt" tab
        ->waitForText(__('parking.lookup.not_found'));
    $page->assertDontSee(__('parking.lookup.registered'))->assertNoJavaScriptErrors();
    expect(snapshotsContain($page, 'ZZ0001'))->toBeFalse();

    // 2. "Ziņot par nezināmu auto" → sighting with a photo (decoded + re-encoded as JPEG in the canvas).
    $page->click(__('parking.lookup.report'))->waitForText(__('parking.sighting.submit'));
    $page->attach('input[type=file][accept^="image/jpeg"]', $heic);
    $page->waitForText(__('parking.sighting.photo_error.format'));
    $page->attach('input[type=file][accept^="image/jpeg"]', $png);
    $page->waitForText(__('parking.sighting.photo_ready'));
    $page->type('sighting-plate', 'ZZ-0001')
        ->click('form[data-method=submit] button[type=submit]')
        ->waitForText(__('parking.sighting.thanks'));
    $page->assertNoJavaScriptErrors();
    expect(snapshotsContain($page, 'ZZ0001'))->toBeFalse();

    $sighting = inBuilding($this->b, fn () => Sighting::query()->sole());
    expect($sighting->photo_path)->not->toBeNull()->and($sighting->photo_bytes)->toBeGreaterThan(0);
    $stored = (string) SightingService::disk()->get($sighting->photo_path);
    [$width] = getimagesizefromstring($stored) ?: [0];
    expect(substr($stored, 0, 3))->toBe("\xFF\xD8\xFF")->and($width)->toBeLessThanOrEqual(1600);

    // 3. Registered plate (typed with a dash, lower case) → "Reģistrēts šajā mājā" — and nothing more.
    $page->navigate('/b/park-house/parking');
    $page->waitForText(__('parking.lookup.question'));
    $page->type('lookup-plate', 'ab-1234')
        ->click('form[data-method=check] button[type=submit]') // not the "Pārbaudīt" tab
        ->waitForText(__('parking.lookup.registered'));
    $page->assertDontSee('Dz. 9')->assertDontSee('AB1234');
    expect(snapshotsContain($page, 'AB1234'))->toBeFalse();

    // 4. Use up the hour (5 per user, the sighting counted too) and hit the friendly countdown on the next try.
    $used = 3; // lookup, sighting, lookup
    for ($i = $used; $i < 5; $i++) {
        inBuilding($this->b, fn () => app(PlateLookupService::class)->lookup($this->resident, 'CD10'.$i, LookupReason::UnknownCar));
    }
    $page->press(__('parking.lookup.again'))->waitForText(__('parking.lookup.question'));
    $page->type('lookup-plate', 'EF 2002')
        ->click('form[data-method=check] button[type=submit]') // not the "Pārbaudīt" tab
        ->waitForText(__('parking.retry.title'));
    $page->assertSee(__('parking.retry.body'));
    $before = $page->script('document.querySelector("[x-data=retryCountdown] [x-text=label]").textContent.trim()');
    $page->wait(1.5);
    $after = $page->script('document.querySelector("[x-data=retryCountdown] [x-text=label]").textContent.trim()');
    expect($before)->toMatch('/^\d+:\d{2}$/')->and($after)->not->toBe($before);
    $page->assertNoJavaScriptErrors();
    $page->screenshot(filename: 'parking-countdown');

    @unlink($png);
    @unlink($heic);
});

<?php

declare(strict_types=1);

use App\Models\HeatingReport;

/*
 * MVP-SPEC B.8 "Browser/HeatingReportSmokeTest" — Package B in real Chromium with the real Vite build and the CSP
 * Livewire/Alpine bundle (no unsafe-eval): the report sheet as a real modal (x-trap.noscroll.inert, Esc), the offline
 * toggle (B AC 12: "Saglabāt" disabled + banner, selection survives reconnecting), the ≤ 4-tap report with the undo
 * countdown, the riser summary sheet opened from the grid (`x-trap="isOpen('col-N')"` call expressions), the sticky
 * "Ziņot" CTA, senior mode (html[data-density=large]) at 320px + 200 % text, and the colour-blind check
 * (DESIGN-SYSTEM §2.3 — deuteranopia / protanopia simulation of the rendered fills).
 * Screenshots for human review land in tests/Browser/Screenshots (git-ignored).
 */
beforeEach(function (): void {
    $this->b = heatingHouse('smoke-heat', 1, 9); // 1 stairwell × 9 floors × 4 = apartments 1..36, column = ((n-1) % 4) + 1
    $this->resident = makeUser();
    member($this->resident, $this->b, 1);
});

/**
 * Two apartments per column → a confirmed column state: col 1 cold, 2 lukewarm, 3 warm, 4 overheated.
 */
function paintFourStates(object $test): void
{
    foreach ([[5, 9, 'cold'], [6, 10, 'lukewarm'], [7, 11, 'warm'], [8, 12, 'overheated']] as [$a, $b, $state]) {
        residentReports($test->b, $a, $state);
        residentReports($test->b, $b, $state);
    }
}

/**
 * x-trap.inert (Alpine focus plugin) hides everything outside the trapped dialog from assistive tech with
 * aria-hidden="true" — the app header is outside every sheet.
 */
function backgroundHidden(object $page): bool
{
    return (bool) $page->script('document.querySelector("body > div header, header").closest("[aria-hidden=true]") !== null');
}

function isInsideDialog(object $page, string $dialog = '[role="dialog"]'): bool
{
    return (bool) $page->script("!!(document.activeElement && document.activeElement.closest('{$dialog}'))");
}

function pressKey(object $page, string $key): void
{
    $page->page()->keyDown($key);
    $page->page()->keyUp($key);
}

it('reports heating through the real modal sheet, survives going offline and shows the undo countdown', function (): void {
    $this->actingAs($this->resident);
    $page = phone('/b/smoke-heat/heating?report=1', warm: true);
    $page->waitForText(__('heating.report.same_everywhere'));
    $page->assertNoJavaScriptErrors();

    // A real modal: focus inside, background inert, page scroll locked; Tab never leaves the sheet.
    waitUntil($page, 'document.activeElement && document.activeElement.closest("[role=dialog]")');
    expect(isInsideDialog($page))->toBeTrue()
        ->and(backgroundHidden($page))->toBeTrue()
        ->and($page->script('getComputedStyle(document.documentElement).overflow'))->toBe('hidden');
    for ($i = 0; $i < 25; $i++) {
        pressKey($page, 'Tab');
    }
    expect(isInsideDialog($page))->toBeTrue();

    // Esc closes; the sticky "Ziņot" CTA reopens it.
    pressKey($page, 'Escape');
    waitUntil($page, '!document.querySelector(".sheet[role=dialog]") || getComputedStyle(document.querySelector(".sheet[role=dialog]")).display === "none"');
    expect(backgroundHidden($page))->toBeFalse();
    $page->click(__('heating.report.cta'))->waitForText(__('heating.report.same_everywhere'));
    waitUntil($page, 'document.activeElement && document.activeElement.closest("[role=dialog]")');
    expect(isInsideDialog($page))->toBeTrue();

    // Offline: banner + disabled "Saglabāt"; the radio selection stays in the browser.
    browserOffline($page, true);
    $page->waitForText(__('shell.offline'));
    $page->radio('state-kitchen', 'cold');
    expect($page->script('document.documentElement.hasAttribute("data-offline")'))->toBeTrue()
        ->and($page->script('getComputedStyle(document.querySelector("form button[type=submit][data-requires-online]")).pointerEvents'))->toBe('none');
    $page->screenshot(filename: 'heating-offline');

    browserOffline($page, false);
    waitUntil($page, '!document.documentElement.hasAttribute("data-offline")');
    expect($page->script('document.querySelector("input[name=state-kitchen][value=cold]").checked'))->toBeTrue()
        ->and($page->script('getComputedStyle(document.querySelector("form button[type=submit][data-requires-online]")).pointerEvents'))->not->toBe('none');

    // ≤ 4 taps: "Visās telpās vienādi → Auksts", "Saglabāt".
    $page->click('button[wire\:click="allSame(\'cold\')"]')
        ->press(__('common.save'))
        ->waitForText(__('heating.report.thanks'));
    $page->assertSee(__('heating.report.undo'))->assertNoJavaScriptErrors();

    $first = (int) $page->script('parseInt(document.querySelector("[x-data=countdown] [x-text=left]").textContent, 10)');
    $page->wait(1.5);
    $second = (int) $page->script('parseInt(document.querySelector("[x-data=countdown] [x-text=left]").textContent, 10)');
    expect($first)->toBeGreaterThan(0)->and($second)->toBeLessThan($first);

    $rows = inBuilding($this->b, fn () => HeatingReport::query()->where('apartment_id', apartment($this->b, 1)->id)->whereNull('superseded_at')->get());
    expect($rows)->not->toBeEmpty()->and($rows->every(fn ($r) => $r->status->value === 'cold'))->toBeTrue();

    $page->press(__('heating.report.ok'));
});

it('opens the riser summary sheet from the grid as a trapped modal and keeps the "Ziņot" CTA in view', function (): void {
    paintFourStates($this);
    $this->actingAs($this->resident);
    $page = phone('/b/smoke-heat/heating', warm: true);
    $page->resize(320, 640);
    $page->waitForText(__('heating.report.cta'));

    // The CTA sticks to the PAGE (display: contents wrapper): visible above the bottom nav at the top, middle and
    // end of a 9-floor stairwell at 320 × 640.
    expect($page->script('document.documentElement.scrollHeight > window.innerHeight'))->toBeTrue();
    foreach (['0', 'document.documentElement.scrollHeight / 2', 'document.documentElement.scrollHeight'] as $y) {
        $page->script("window.scrollTo(0, {$y})");
        $page->wait(0.2);
        $box = $page->script(<<<'JS'
            (() => {
                const cta = [...document.querySelectorAll('.sticky-action button')].find(b => b.offsetParent !== null);
                const nav = document.querySelector('nav.fixed');
                const r = cta.getBoundingClientRect();
                return { top: r.top, bottom: r.bottom, navTop: nav ? nav.getBoundingClientRect().top : window.innerHeight };
            })()
            JS);
        expect($box['top'])->toBeGreaterThanOrEqual(0, "CTA off-screen at scroll {$y}")
            ->and($box['bottom'])->toBeLessThanOrEqual($box['navTop'] + 1, "CTA under the bottom nav at scroll {$y}");
    }
    $page->script('window.scrollTo(0, 0)');

    // Riser summary sheet of column 2: x-trap="isOpen('col-2')" (a call expression in the CSP build) traps focus.
    $page->click('tbody tr:first-child td button[data-key="col-2"]');
    waitUntil($page, 'getComputedStyle(document.querySelector("[data-panel=col-2]")).display !== "none"');
    // x-trap activates on the next tick and moves focus to the sheet's first control ("Aizvērt").
    waitUntil($page, '!!(document.activeElement && document.activeElement.closest("[data-panel=col-2]"))');
    expect(isInsideDialog($page, '[data-panel="col-2"]'))->toBeTrue()
        ->and(backgroundHidden($page))->toBeTrue();
    $page->assertSee(__('heating.column.title', ['n' => 2, 'section' => 1]))->assertNoJavaScriptErrors();
    for ($i = 0; $i < 15; $i++) {
        pressKey($page, 'Tab');
    }
    expect(isInsideDialog($page, '[data-panel="col-2"]'))->toBeTrue();
    $page->screenshot(filename: 'heating-riser-sheet-320');

    pressKey($page, 'Escape');
    waitUntil($page, 'getComputedStyle(document.querySelector("[data-panel=col-2]")).display === "none"');
    expect(backgroundHidden($page))->toBeFalse();
});

it('keeps senior mode usable at 320px and at 200 % text (2 × 2 states, 48px targets, no sideways scroll)', function (): void {
    $this->resident->forceFill(['text_size' => 'large'])->save();
    $this->actingAs($this->resident);
    $page = phone('/b/smoke-heat/heating?report=1', warm: true);
    $page->resize(320, 700);
    $page->waitForText(__('heating.report.same_everywhere'));

    expect($page->script('document.documentElement.dataset.density'))->toBe('large')
        ->and((float) $page->script('parseFloat(getComputedStyle(document.documentElement).fontSize)'))->toBe(19.0)
        // 2 × 2 state grid below 30rem (= 570px in senior mode).
        ->and($page->script('getComputedStyle(document.querySelector(".status-grid")).gridTemplateColumns.split(" ").length'))->toBe(2);

    $small = $page->script(<<<'JS'
        [...document.querySelectorAll('[role=dialog] :is(button, .btn, .choice, .status-option span), .sticky-action button')]
            .filter(e => e.offsetParent !== null && !e.classList.contains('btn-link'))
            .map(e => ({ t: (e.innerText || e.getAttribute('aria-label') || e.tagName).trim().slice(0, 30), h: Math.round(e.getBoundingClientRect().height), w: Math.round(e.getBoundingClientRect().width) }))
            .filter(x => x.h < 48 || x.w < 48)
        JS);
    expect($small)->toBe([], 'Targets under 48 × 48 px: '.json_encode($small, JSON_UNESCAPED_UNICODE));

    assertNoHorizontalScroll($page, 'senior 320px, sheet open');
    $page->screenshot(filename: 'heating-320-senior-sheet');

    pressKey($page, 'Escape');
    $page->script('document.querySelectorAll("details").forEach(d => { d.open = true; })'); // legend + privacy boxes open
    assertNoHorizontalScroll($page, 'senior 320px, grid');
    $page->screenshot(filename: 'heating-320-senior-grid');

    scaleRootText($page, 200);
    $page->wait(0.3);
    assertNoHorizontalScroll($page, 'senior 320px at 200 % text');
    $page->screenshot(filename: 'heating-320-senior-200pct');
});

it('keeps the four heating states distinguishable under deuteranopia and protanopia', function (): void {
    paintFourStates($this);
    $this->actingAs($this->resident);
    $page = phone('/b/smoke-heat/heating', warm: true);
    $page->waitForText(__('heating.report.cta'));

    $cells = $page->script(<<<'JS'
        Object.fromEntries(['cold', 'lukewarm', 'warm', 'overheated'].map(s => {
            // Grid cells only (the mini map uses the same fills without icons).
            const td = [...document.querySelectorAll('td.heat-cell.heat-' + s)].find(c => c.querySelector('button svg'));
            if (!td) return [s, null];
            const cs = getComputedStyle(td);
            const icon = td.querySelector('button svg');
            return [s, { bg: cs.backgroundColor, image: cs.backgroundImage, icon: icon ? icon.innerHTML.replace(/\s+/g, ' ') : null }];
        }))
        JS);

    foreach (['cold', 'lukewarm', 'warm', 'overheated'] as $state) {
        expect($cells[$state])->not->toBeNull("No rendered {$state} cell")
            ->and($cells[$state]['icon'])->not->toBeNull("{$state} cell has no icon (never colour alone)");
    }
    // Every state has its OWN icon.
    expect(collect($cells)->pluck('icon')->unique())->toHaveCount(4)
        // Overheated is the state that shares luminance with cold under deuteranopia — it carries the 45° hatch.
        ->and($cells['overheated']['image'])->not->toBe('none');

    foreach (['none', 'deuteranopia', 'protanopia'] as $vision) {
        $l = collect(['cold', 'lukewarm', 'warm'])->mapWithKeys(fn ($s) => [$s => simulatedLuminance($cells[$s]['bg'], $vision)]);
        foreach ([['cold', 'lukewarm'], ['cold', 'warm'], ['lukewarm', 'warm']] as [$x, $y]) {
            $ratio = (max($l[$x], $l[$y]) + 0.05) / (min($l[$x], $l[$y]) + 0.05);
            expect($ratio)->toBeGreaterThanOrEqual(1.5, "{$x} vs {$y} under {$vision}: luminance ratio {$ratio}");
        }

        if ($vision !== 'none') {
            $page->script(cvdFilterScript($vision));
            $page->screenshot(filename: "heating-grid-{$vision}");
        }
    }
});

/**
 * Relative luminance of a CSS rgb() colour as seen with the given colour-vision deficiency
 * (Machado, Oliveira & Fernandes 2009, severity 1.0, applied in linear RGB).
 */
function simulatedLuminance(string $css, string $vision): float
{
    preg_match_all('/[\d.]+/', $css, $m);
    $lin = array_map(function (string $v): float {
        $c = ((float) $v) / 255;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, array_slice($m[0], 0, 3));

    $matrix = match ($vision) {
        'deuteranopia' => [[0.367322, 0.860646, -0.227968], [0.280085, 0.672501, 0.047413], [-0.011820, 0.042940, 0.968881]],
        'protanopia' => [[0.152286, 1.052583, -0.204868], [0.114503, 0.786281, 0.099216], [-0.003882, -0.048116, 1.051998]],
        default => [[1, 0, 0], [0, 1, 0], [0, 0, 1]],
    };
    $rgb = array_map(fn (array $row): float => max(0.0, min(1.0, $row[0] * $lin[0] + $row[1] * $lin[1] + $row[2] * $lin[2])), $matrix);

    return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
}

/**
 * Applies the same Machado matrix to the whole page with an SVG feColorMatrix (sRGB approximation) for the
 * review screenshots.
 */
function cvdFilterScript(string $vision): string
{
    $values = $vision === 'deuteranopia'
        ? '0.367 0.861 -0.228 0 0 0.280 0.673 0.047 0 0 -0.012 0.043 0.969 0 0 0 0 0 1 0'
        : '0.152 1.053 -0.205 0 0 0.115 0.786 0.099 0 0 -0.004 -0.048 1.052 0 0 0 0 0 1 0';

    return <<<JS
        (() => {
            const ns = 'http://www.w3.org/2000/svg';
            let svg = document.getElementById('cvd-sim');
            if (!svg) {
                svg = document.createElementNS(ns, 'svg');
                svg.id = 'cvd-sim';
                svg.setAttribute('width', '0');
                svg.setAttribute('height', '0');
                svg.style.position = 'absolute';
                document.body.appendChild(svg);
            }
            svg.innerHTML = '<filter id="cvd" color-interpolation-filters="linearRGB"><feColorMatrix type="matrix" values="{$values}"/></filter>';
            document.documentElement.style.filter = 'url(#cvd)';
            return true;
        })()
        JS;
}

it('updates the map right after saving: no "be the first", no ?report=1 left in the address bar (FIX-007.1)', function (): void {
    $this->actingAs($this->resident);
    $page = phone('/b/smoke-heat/heating?report=1', warm: true);
    $page->waitForText(__('heating.report.same_everywhere'));
    $page->assertSee(__('heating.empty.section'));

    $page->click('button[wire\:click="allSame(\'cold\')"]')
        ->press(__('common.save'))
        ->waitForText(__('heating.report.thanks'));

    // The grid re-rendered behind the sheet (heating-reported) and a reload no longer reopens the sheet.
    waitUntil($page, '!document.body.innerText.includes('.json_encode(__('heating.empty.section')).')');
    expect($page->script('location.search'))->not->toContain('report=1');
    $page->press(__('heating.report.ok'));
    $page->assertNoJavaScriptErrors();
});

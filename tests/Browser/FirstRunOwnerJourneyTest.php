<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\BuildingRole;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PucesIela51Seeder;

/*
 * FIRSTRUN-001 acceptance — the owner's whole first run on a production-like seed (Series602 + Pūces iela 51,
 * structure only) and ONE account: a platform_admin with no house role and no apartment. He signs in by e-mail code,
 * adds a passkey (this session's second factor) and must never meet a blank page or a bare 403 / 404: every empty
 * state says why and links to the fix. Then, using only on-screen buttons: "Kļūt par šīs mājas administratoru" →
 * "Sākt šeit" checklist → (house opened) "Piesaistīt mani dzīvoklim" 173 → the checklist shows the progress →
 * "Admin skats ⇄ Iedzīvotāja skats" both ways, in LV and in RU.
 *
 * FirstRunSmokeTest keeps the small-house path (/home → /start, opening 1 of 4, the closed house in the resident
 * view); this file does not repeat it. Synthetic data only (an @example.test address, no residents).
 *
 * Run: scripts/dev.sh browser --filter=FirstRunOwnerJourneyTest
 */

/**
 * The page is a real page, not a dead end: no error page (403 / 404 / 500 / 503 titles), visible text, a heading, and
 * at least one way forward (a link or a button) inside the main content.
 */
function firstRunAssertNotBlank(object $page, string $label): void
{
    $errorTitles = array_map(static fn (string $code): string => __("errors.{$code}.title"), ['403', '404', '500', '503']);
    $text = (string) $page->script('(document.body ? document.body.innerText : "").trim()');

    foreach ($errorTitles as $title) {
        expect(str_contains($text, $title))->toBeFalse("{$label}: shows the error page \"{$title}\" — a dead end (".substr($text, 0, 160).')');
    }

    // A bare Filament / Laravel 403 also has no role=alert card of our errors layout.
    expect((bool) $page->script("!!document.querySelector('section[role=alert] h1') && document.querySelectorAll('a[href], button').length <= 3"))
        ->toBeFalse("{$label}: looks like a bare error card");

    $main = "(document.querySelector('main, .fi-main, [role=main]') || document.body)";
    $mainText = (string) $page->script("{$main}.innerText.trim()");
    expect(mb_strlen($mainText))->toBeGreaterThan(40, "{$label}: the main content is (nearly) blank: \"{$mainText}\"");
    expect((bool) $page->script("!!{$main}.querySelector('h1, h2, .fi-header-heading')"))->toBeTrue("{$label}: no heading — the page does not say where the person is");
    expect((int) $page->script("{$main}.querySelectorAll('a[href]:not([href=\"#\"]), button:not([disabled])').length"))
        ->toBeGreaterThan(0, "{$label}: no link or button — an empty state must link to the fix");

    $page->assertNoJavaScriptErrors();
}

/**
 * Open $path in the same tab and wait until the new document has booted (redirects included).
 */
function firstRunOpen(object $page, string $path): void
{
    openPath($page, $path);
    waitForBoot($page);
}

/**
 * Statuses of the "Sākt šeit" steps on the current panel page: step key => done|todo|blocked|optional.
 *
 * @return array<string, string>
 */
function firstRunChecklist(object $page): array
{
    waitUntil($page, "!!document.querySelector('[data-testid=\"getting-started\"]')", 15);

    return (array) $page->script(<<<'JS'
        Object.fromEntries([...document.querySelectorAll('[data-testid="getting-started"] [data-testid^="step-"][data-status]')]
            .map((li) => [li.dataset.testid.replace(/^step-/, ''), li.dataset.status]))
        JS);
}

/**
 * The visible view switch says which view this is, in $locale, and offers the other one.
 */
function firstRunAssertView(object $page, string $current, string $locale): void
{
    $other = $current === 'admin' ? 'resident' : 'admin';
    waitUntil($page, "!!document.querySelector('[data-testid=\"view-switch\"][data-view=\"{$current}\"]')", 15);
    $switch = (string) $page->script("document.querySelector('[data-testid=\"view-switch\"][data-view=\"{$current}\"]').textContent");

    expect($page->script('document.documentElement.lang'))->toBe($locale)
        ->and($switch)->toContain(trans('start.view.'.$current.'_short', [], $locale)) // phone width: short words
        ->and($switch)->toContain(trans('start.view.'.$other.'_short', [], $locale))
        ->and((bool) $page->script("!!document.querySelector('[data-testid=\"view-switch\"] [data-view-target=\"{$other}\"][href]')"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
}

it('takes a lone platform admin from a fresh seed to his own house and apartment without a single dead end', function (): void {
    useMailpit();
    $this->seed(DatabaseSeeder::class); // Series602, notices, legal, document masters, Pūces iela 51 — structure only
    $slug = PucesIela51Seeder::SLUG;
    $b = Building::query()->where('slug', $slug)->firstOrFail();
    expect(inBuilding($b, fn () => Apartment::query()->count()))->toBe(180)
        ->and(inBuilding($b, fn () => BuildingRole::query()->count()))->toBe(0);

    $email = browserEmail('firstrun-owner');
    $owner = makeUser($email, ['platform_role' => 'platform_admin']);

    // ---- Sign in like the owner: e-mail code → passkey (strong second factor of this session) ----
    $page = visit('/login')->withHost('localhost')->on()->mobile()->assertPathIs('/login');
    virtualAuthenticator($page);
    $page->type('email', $email)->press(__('auth.email.send'))->waitForText(__('auth.email.sent_title'));
    $page->type('code', mailpitCode($email))->press(__('auth.login'));
    waitUntil($page, "location.pathname === '/me/passkey'", 15);
    $page->click('[data-testid="passkey-register"]');
    waitUntil($page, "location.pathname !== '/me/passkey'", 15);
    waitForBoot($page);
    $page->resize(320, 720);

    // ---- 1. No blank page anywhere he can land (before he has any role or apartment) ----
    $landing = [
        'home' => '/home',
        'admin entry' => '/admin',
        'platform dashboard' => '/admin/platform',
        'building panel without a role' => "/admin/b/{$slug}",
        'resident home without an apartment' => "/b/{$slug}",
        'resident "Mans dzīvoklis" without an apartment' => "/b/{$slug}/me",
        'resident heating (module off, house closed)' => "/b/{$slug}/heating",
        'resident water (module off, house closed)' => "/b/{$slug}/water",
    ];
    foreach ($landing as $label => $path) {
        firstRunOpen($page, $path);
        firstRunAssertNotBlank($page, $label);
        assertNoHorizontalScroll($page, $label);
    }

    // The building panel without a role must not be a bare 403: it leads to the house page with the fix.
    firstRunOpen($page, "/admin/b/{$slug}");
    waitUntil($page, "!!document.querySelector('[data-testid=\"house-claim\"], a[href*=\"/admin/houses/{$slug}\"]')", 15);
    $page->assertSee(__('start.house.claim_button'));

    // ---- 2. "Kļūt par šīs mājas administratoru" (on-screen button, strong step-up = the passkey of this session) ----
    firstRunOpen($page, "/admin/houses/{$slug}");
    firstRunAssertNotBlank($page, 'platform house page');
    $page->click('[data-testid="house-claim"]');
    waitUntil($page, "!!document.querySelector('[data-testid=\"house-panel\"]')", 15);
    $page->assertNoJavaScriptErrors();

    $roles = inBuilding($b, fn () => BuildingRole::query()->current()->where('user_id', $owner->id)->get());
    expect($roles)->toHaveCount(1)
        ->and($roles[0]->role)->toBe(BuildingRoleType::BuildingAdmin);

    // ---- 3. The house dashboard opens with "Sākt šeit": admin done, opening to do, "me" blocked (house closed) ----
    $page->click('[data-testid="house-panel"]');
    waitUntil($page, "location.pathname === '/admin/b/{$slug}'", 15);
    waitForBoot($page);
    firstRunAssertNotBlank($page, 'building panel dashboard as admin');
    $page->assertSee(__('start.steps.heading')); // "Sākt šeit"
    $before = firstRunChecklist($page);
    expect($before)->toHaveKeys(['admin', 'opening', 'modules', 'me'])
        ->and($before['admin'])->toBe('done')
        ->and($before['opening'])->toBe('todo')
        ->and($before['me'])->not->toBe('done');
    // Every step that is not done offers its fix as a link (no dead end on the checklist either).
    foreach (array_keys(array_filter($before, static fn (string $s): bool => $s !== 'done')) as $key) {
        expect((bool) $page->script("!!document.querySelector('[data-testid=\"step-{$key}\"] a[href]')"))
            ->toBeTrue("checklist step {$key} has no link to its fix");
    }

    // Before the house is open, "Piesaistīt mani dzīvoklim" explains why it cannot be done yet (not a blank card).
    firstRunOpen($page, "/admin/houses/{$slug}");
    $page->assertSee(__('start.house.link_closed'));

    // The opening itself is the wizard's job (covered by WizardFlowTest / OpeningWizardSmokeTest): L-core in-process.
    openGate($b);

    // ---- 4. "Piesaistīt mani dzīvoklim" 173 — reached from the checklist's own link ----
    firstRunOpen($page, "/admin/b/{$slug}");
    $page->click('[data-testid="step-me"] a[href]');
    waitUntil($page, "location.pathname === '/b/{$slug}/me'", 15);
    waitForBoot($page);
    waitUntil($page, "!!document.querySelector('[data-testid=\"link-myself\"]')", 10);
    $page->type('#link-apartment', '173')
        ->click('[data-testid="link-submit"]');
    waitUntil($page, "!document.querySelector('[data-testid=\"link-myself\"]') && document.readyState === 'complete'", 15);
    $page->assertNoJavaScriptErrors();

    $membership = inBuilding($b, fn () => ApartmentMembership::query()->where('user_id', $owner->id)->sole());
    expect((int) $membership->apartment_id)->toBe((int) apartment($b, 173)->id)
        ->and($membership->status)->toBe(MembershipStatus::Approved); // platform admin: self-approved under strong step-up
    firstRunAssertNotBlank($page, 'Mans dzīvoklis after linking');

    // ---- 5. The checklist reflects the progress ----
    firstRunOpen($page, "/admin/b/{$slug}");
    $after = firstRunChecklist($page);
    $done = static fn (array $steps): int => count(array_filter($steps, static fn (string $s): bool => $s === 'done'));
    expect($after['admin'])->toBe('done')
        ->and($after['opening'])->toBe('done')
        ->and($after['me'])->toBe('done')
        ->and($done($after))->toBeGreaterThan($done($before));
    $page->assertSee(__('start.steps.progress', ['done' => $done($after), 'total' => count($after)]));

    // ---- 6. Admin ⇄ Iedzīvotāja skats, both ways, in LV and in RU (same house each time) ----
    foreach (['lv', 'ru'] as $locale) {
        // In the resident app: pick the language with the LV | RU toggle of the top bar.
        firstRunOpen($page, "/b/{$slug}");
        if ($page->script('document.documentElement.lang') !== $locale) {
            $page->press(strtoupper($locale));
            waitUntil($page, 'document.documentElement.lang === '.json_encode($locale)." && document.readyState === 'complete'", 15);
            waitForBoot($page);
        }
        firstRunAssertView($page, 'resident', $locale);
        firstRunAssertNotBlank($page, "resident home ({$locale})");
        assertNoHorizontalScroll($page, "resident home with the view switch ({$locale})");

        // Iedzīvotāja skats → Admin skats: the same house's panel, same language, with the checklist.
        $page->click('[data-testid="view-switch"] [data-view-target="admin"]');
        waitUntil($page, "location.pathname === '/admin/b/{$slug}'", 15);
        waitForBoot($page);
        firstRunAssertView($page, 'admin', $locale);
        $page->assertSee(trans('start.steps.heading', [], $locale));
        firstRunAssertNotBlank($page, "building panel ({$locale})");

        // Admin skats → Iedzīvotāja skats: back to the same house in the resident app.
        $page->click('[data-testid="view-switch"] [data-view-target="resident"]');
        waitUntil($page, "location.pathname === '/b/{$slug}'", 15);
        waitForBoot($page);
        firstRunAssertView($page, 'resident', $locale);
    }

    // The remembered view never grants anything: /home simply opens the last view of a house he may open.
    firstRunOpen($page, '/home');
    firstRunAssertNotBlank($page, '/home after the round trip');
});

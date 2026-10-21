<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Casts\Bytea;
use App\Enums\BuildingRoleType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\PrivacyNoticeKind;
use App\Enums\ReportSource;
use App\Gdpr\CoreGate;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Models\BuildingRole;
use App\Models\BuildingSeries;
use App\Models\PrivacyNoticeVersion;
use App\Models\Riser;
use App\Models\Section;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Buildings\BuildingGenerator;
use App\Services\Buildings\GeneratorInput;
use App\Services\Buildings\SeriesTemplateData;
use App\Services\Heating\HeatingGridQuery;
use App\Services\Heating\HeatingReportService;
use App\Services\Heating\HeatingSubmission;
use App\Services\Heating\Views\HeatingSectionView;
use App\Services\Invites\InviteCodeService;
use App\Services\Parking\VehicleRegistry;
use App\Tenancy\CurrentBuilding;
use Database\Seeders\PrivacyNoticeSeeder;
use Database\Seeders\Series602Seeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Page;
use Tests\BrowserTestCase;
use Tests\Support\DbRoles;
use Tests\TestCase;

/*
 * Synthetic data only (MVP-SPEC §0): fictional e-mails @example.test, no real residents, phones or plates.
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit', 'Arch');
pest()->extend(BrowserTestCase::class)->use(RefreshDatabase::class)->in('Browser');

// CI runners are slower than local Docker: a 5 s page-load timeout made the deploy gate flaky (Karen, round 1).
pest()->browser()->timeout(15_000);

/**
 * Run $callback inside a building context (test helper; app code uses the allow-listed classes only).
 *
 * @template T
 *
 * @param  callable(Building): T  $callback
 * @return T
 */
function inBuilding(Building $building, callable $callback): mixed
{
    return CurrentBuilding::runFor($building, $callback);
}

function series602(): BuildingSeries
{
    if (! BuildingSeries::query()->where('code', '602')->exists()) {
        (new Series602Seeder)->run();
    }

    return BuildingSeries::query()->where('code', '602')->firstOrFail();
}

/**
 * A generated building (default: 2 stairwells × 3 floors × 4 apartments, numbering 1..24).
 */
function makeBuilding(string $slug = 'test-house', int $sections = 2, int $floors = 3, string $layout = '2-2-3-3', bool $open = false): Building
{
    $series = series602();

    $building = Building::query()->create([
        'slug' => $slug,
        'name' => 'Testa iela '.strlen($slug),
        'address_line' => 'Testa iela '.strlen($slug),
        'city' => 'Rīga',
        'series_id' => $series->id,
        'floors' => $floors,
    ]);

    inBuilding($building, fn (Building $b) => app(BuildingGenerator::class)->generate(
        $b,
        SeriesTemplateData::fromSeries($series),
        GeneratorInput::uniform($sections, $floors, $layout),
    ));

    if ($open) {
        openGate($building);
    }

    return $building->refresh();
}

/**
 * Satisfy gate L-core: controller, DPA (core/heating/outages), published building notice.
 */
function openGate(Building $building): BuildingPrivacyNotice
{
    $building->forceFill(['settings' => $building->settings->with([
        'controller' => ['name' => 'Testa biedrība', 'contact' => 'dati@example.test', 'basis_ref' => 'Lēmums 1'],
        'dpa_signed_at' => '2026-10-01',
        'dpa_ref' => 'DPA-TEST-1',
        'dpa_scope' => ['core', 'heating', 'outages'],
    ])])->save();

    if (! PrivacyNoticeVersion::query()->exists()) {
        (new PrivacyNoticeSeeder)->run();
    }
    PrivacyNoticeVersion::query()->update(['published_at' => now()->subDay()]);
    $template = PrivacyNoticeVersion::query()->where('kind', PrivacyNoticeKind::BuildingTemplate->value)->firstOrFail();

    return inBuilding($building, fn () => BuildingPrivacyNotice::query()->create([
        'template_version_id' => $template->id,
        'controller_snapshot' => ['name' => 'Testa biedrība', 'contact' => 'dati@example.test'],
        'covers_modules' => ['core', 'heating', 'outages'],
        'published_at' => now()->subHour(),
    ]));
}

function makeUser(?string $email = null, array $attributes = []): User
{
    static $n = 0;
    $n++;

    $user = User::query()->create(['email' => $email ?? "user{$n}@example.test", 'locale' => 'lv']);
    $user->forceFill(['email_verified_at' => now()] + $attributes)->save();

    return $user->refresh();
}

function apartment(Building $building, int $number): Apartment
{
    return inBuilding($building, fn () => Apartment::query()->where('number', $number)->firstOrFail());
}

function section(Building $building, int $number): Section
{
    return inBuilding($building, fn () => Section::query()->where('number', $number)->firstOrFail());
}

/**
 * Membership without the gate guard (fixtures). Gate-related tests create memberships through the real flow.
 */
function member(User $user, Building $building, int $apartmentNumber, MembershipStatus $status = MembershipStatus::Approved, MembershipRole $role = MembershipRole::Owner): ApartmentMembership
{
    $apartment = apartment($building, $apartmentNumber);

    return inBuilding($building, function () use ($user, $apartment, $status, $role, $building): ApartmentMembership {
        $wasOpen = CoreGate::status($building)->open;
        if (! $wasOpen) {
            openGate($building);
        }

        return ApartmentMembership::query()->create([
            'apartment_id' => $apartment->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
            'approved_at' => $status === MembershipStatus::Approved ? now() : null,
        ]);
    });
}

function grantRole(User $user, Building $building, BuildingRoleType $role, ?int $sectionNumber = null): BuildingRole
{
    $sectionId = $sectionNumber !== null ? section($building, $sectionNumber)->id : null;

    return inBuilding($building, fn () => BuildingRole::query()->create([
        'user_id' => $user->id,
        'role' => $role,
        'section_id' => $sectionId,
        'granted_at' => now(),
        'expires_at' => now()->addYear(),
    ]));
}

/**
 * Create an invite and return the PLAINTEXT code (test only; production keeps codes in memory during print).
 */
function makeInvite(Building $building, int $apartmentNumber, array $attributes = []): string
{
    $codes = app(InviteCodeService::class);
    $code = $codes->generate();
    $apartment = apartment($building, $apartmentNumber);

    inBuilding($building, fn () => ApartmentInvite::query()->create($attributes + [
        'apartment_id' => $apartment->id,
        'code_hmac' => $codes->hmac($code),
        'max_uses' => 3,
        'expires_at' => now()->addDays(60),
    ]));

    return $code;
}

/**
 * Mark the session as freshly stepped-up (method: email|totp).
 */
/**
 * A fresh step-up in the test session. Default method = what the user's roles need (TOTP for building_admin /
 * parking_officer / platform_admin — StepUpService::requiresMfa, e-mail code otherwise).
 */
function steppedUp(User $user, ?string $method = null): void
{
    $method ??= app(StepUpService::class)->requiresMfa($user) ? 'totp' : 'email';
    test()->withSession([StepUpService::SESSION_KEY => ['user' => $user->id, 'at' => now()->getTimestamp(), 'method' => $method]]);
}

/*
 * ---- Package B helpers (heating & outages) ----
 */

/**
 * A generated, gate-open building with the heating + outages modules on (default 2 stairwells × 9 floors × 4).
 */
function heatingHouse(string $slug = 'heat-house', int $sections = 2, int $floors = 9, array $modules = ['heating' => true, 'outages' => true]): Building
{
    $b = makeBuilding($slug, $sections, $floors, open: true);
    $b->forceFill(['settings' => $b->settings->with(['modules' => $modules])])->save();

    return $b->refresh();
}

/**
 * Submit a heating report for an apartment as $user (states: room code => status).
 *
 * @param  array<string, string>  $states
 */
function reportHeating(User $user, Building $b, int $apartmentNumber, array $states, ReportSource $source = ReportSource::Self): HeatingSubmission
{
    $apartment = apartment($b, $apartmentNumber);

    return inBuilding($b, fn () => app(HeatingReportService::class)->submit($user, $apartment, $states, source: $source));
}

/**
 * Approved resident of an apartment who reports cold/warm in every radiator room.
 */
function residentReports(Building $b, int $apartmentNumber, string $status = 'cold', ?array $states = null): User
{
    $user = makeUser();
    member($user, $b, $apartmentNumber);
    reportHeating($user, $b, $apartmentNumber, $states ?? ['kitchen' => $status, 'room1' => $status, 'room2' => $status]);

    return $user;
}

function residentSectionView(Building $b, int $sectionNumber, string $roundKey = 'rolling'): HeatingSectionView
{
    return inBuilding($b, fn () => app(HeatingGridQuery::class)->residentSection(section($b, $sectionNumber), $roundKey));
}

function riserId(Building $b, string $code): int
{
    return (int) inBuilding($b, fn () => Riser::query()->where('code', $code)->value('id'));
}

/*
 * ---- Package C helpers (parking) ----
 */

/**
 * A generated, gate-open building at parking gate level $level (0, 1 or 2); at L2 the resident module is ON unless
 * $module is false. Default: 2 stairwells × 3 floors × 4 apartments (1..24).
 */
function parkingHouse(string $slug = 'park-house', int $level = 2, bool $module = true, int $sections = 2, int $floors = 3): Building
{
    $b = makeBuilding($slug, $sections, $floors, open: true);

    if ($level >= 1) {
        $b->forceFill(['settings' => $b->settings->with(['dpa_scope' => ['core', 'heating', 'outages', 'parking']])])->save();
    }

    if ($level >= 2) {
        $b->forceFill(['settings' => $b->settings->with([
            'dpia_completed_at' => '2026-10-10',
            'mandate_ref' => 'Kopības lēmums Nr. 1 (tests)',
            'modules' => ['parking' => $module],
        ])])->save();

        $template = PrivacyNoticeVersion::query()->where('kind', PrivacyNoticeKind::BuildingTemplate->value)->orderByDesc('id')->firstOrFail();
        inBuilding($b, fn () => BuildingPrivacyNotice::query()->create([
            'template_version_id' => $template->id,
            'controller_snapshot' => ['name' => 'Testa biedrība', 'contact' => 'dati@example.test'],
            'covers_modules' => ['core', 'heating', 'outages', 'parking'],
            'published_at' => now()->subMinutes(5),
        ]));
    }

    return $b->refresh();
}

/**
 * parking_officer of the building (with an e-mail, so step-up works).
 */
function parkingOfficer(Building $b, ?string $email = null): User
{
    $officer = makeUser($email);
    grantRole($officer, $b, BuildingRoleType::ParkingOfficer);

    return $officer;
}

/**
 * An ACTIVE vehicle of an apartment, created by an officer (synthetic plates only, e.g. "AB1234").
 */
function activeVehicle(Building $b, int $apartmentNumber, string $plate, ?User $officer = null): Vehicle
{
    $officer ??= parkingOfficer($b);
    $apartment = apartment($b, $apartmentNumber);

    return inBuilding($b, fn () => app(VehicleRegistry::class)->officerCreate($officer, $apartment, $plate));
}

/**
 * Approved owner of an apartment in a parking house.
 */
function parkingResident(Building $b, int $apartmentNumber, MembershipRole $role = MembershipRole::Owner): User
{
    $user = makeUser();
    member($user, $b, $apartmentNumber, role: $role);

    return $user;
}

/*
 * Committed fixtures for retention tests (the majas_retention connection cannot see the test transaction).
 */
function retentionBuilding(Connection $owner, string $slug): array
{
    $now = now();
    $buildingId = (int) $owner->table('buildings')->insertGetId([
        'slug' => $slug, 'name' => $slug, 'address_line' => $slug, 'address_search' => $slug, 'city' => 'Rīga', 'floors' => 1,
        'settings' => '{}', 'status' => 'draft', 'default_locale' => 'lv', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $sectionId = (int) $owner->table('sections')->insertGetId(['building_id' => $buildingId, 'number' => 1, 'first_apartment_no' => 1, 'apartments_per_floor' => 4, 'floors' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $apartments = [];
    foreach ([1, 2, 3, 4] as $n) {
        $apartments[$n] = (int) $owner->table('apartments')->insertGetId(['building_id' => $buildingId, 'section_id' => $sectionId, 'number' => $n, 'floor' => 1, 'position' => $n, 'created_at' => $now, 'updated_at' => $now]);
    }

    return [$buildingId, $apartments];
}

function retentionVehicle(Connection $owner, int $buildingId, int $apartmentId, array $attrs = []): string
{
    $id = strtolower((string) Str::ulid());
    $owner->table('vehicles')->insert($attrs + [
        'id' => $id, 'building_id' => $buildingId, 'apartment_id' => $apartmentId,
        'plate_ciphertext' => Bytea::literal(random_bytes(40)), 'plate_bidx' => Bytea::literal(random_bytes(32)), 'plate_key_version' => 1,
        'status' => 'active', 'source' => 'officer', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function cleanupRetentionBuilding(Connection $owner, int $buildingId): void
{
    DbRoles::wipeChain($buildingId);
    foreach (['parking_officer_tasks', 'permits', 'vehicles', 'apartment_memberships', 'apartments', 'sections'] as $table) {
        $owner->table($table)->where('building_id', $buildingId)->delete();
    }
    $owner->table('buildings')->where('id', $buildingId)->delete();
}

/*
 * ---- Real-browser helpers (tests/Browser, pest-plugin-browser + Playwright Chromium) ----
 */

/**
 * Base URL of the Mailpit HTTP API (compose service `mailpit`; CI service on localhost).
 */
function mailpitUrl(): string
{
    return rtrim((string) (getenv('MAILPIT_URL') ?: 'http://mailpit:8025'), '/');
}

/**
 * Send this test's mail through real SMTP into Mailpit instead of the `array` mailer.
 */
function useMailpit(): void
{
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.scheme' => null,
        'mail.mailers.smtp.host' => getenv('MAILPIT_SMTP_HOST') ?: 'mailpit',
        'mail.mailers.smtp.port' => (int) (getenv('MAILPIT_SMTP_PORT') ?: 1025),
        'mail.mailers.smtp.username' => null,
        'mail.mailers.smtp.password' => null,
    ]);
}

/**
 * A unique synthetic address per run, so Mailpit searches never pick up an older message.
 */
function browserEmail(string $who): string
{
    return $who.'-'.Str::lower(Str::random(10)).'@example.test';
}

/**
 * The 6-digit code ("123 456") from the newest Mailpit message to $to, polling up to $seconds.
 */
function mailpitCode(string $to, int $seconds = 15): string
{
    $deadline = microtime(true) + $seconds;
    do {
        $found = Http::timeout(5)->get(mailpitUrl().'/api/v1/search', ['query' => 'to:"'.$to.'"', 'limit' => 1])->json('messages.0.ID');
        if (is_string($found)) {
            $text = (string) Http::timeout(5)->get(mailpitUrl().'/api/v1/message/'.$found)->json('Text');
            if (preg_match('/(?<!\d)(\d{3}) (\d{3})(?!\d)/', $text, $m) === 1) {
                return $m[1].$m[2];
            }
        }
        usleep(250_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("No code mail for {$to} in Mailpit (".mailpitUrl().').');
}

/**
 * Real network offline/online for the page's browser context (Playwright BrowserContext.setOffline): fires the
 * window `offline` / `online` events and makes every request fail, exactly like a dead stairwell connection.
 */
function browserOffline(object $page, bool $offline): void
{
    /** @var Page $playwrightPage */
    $playwrightPage = $page->page();
    $context = $playwrightPage->context();
    $guid = (new ReflectionProperty($context, 'guid'))->getValue($context);
    iterator_to_array(Client::instance()->execute($guid, 'setOffline', ['offline' => $offline]));
}

/**
 * A Chromium virtual WebAuthn authenticator on the page (CDP WebAuthn domain through Playwright's CDP session):
 * a platform authenticator with resident keys and user verification that answers every ceremony at once — the
 * real browser half of a passkey (navigator.credentials, @simplewebauthn/browser, Alpine, Livewire) end to end.
 * The page must be served from `localhost` (WebAuthn refuses an IP address as the RP ID): visit()->withHost().
 */
function virtualAuthenticator(object $page): void
{
    /** @var Page $playwrightPage */
    $playwrightPage = $page->page();
    $context = $playwrightPage->context();
    $contextGuid = (new ReflectionProperty($context, 'guid'))->getValue($context);
    $pageGuid = (new ReflectionProperty($playwrightPage, 'guid'))->getValue($playwrightPage);

    $sessionGuid = null;
    foreach (Client::instance()->execute($contextGuid, 'newCDPSession', ['page' => ['guid' => $pageGuid]]) as $message) {
        $sessionGuid = $message['result']['session']['guid'] ?? $sessionGuid;
    }
    if (! is_string($sessionGuid)) {
        throw new RuntimeException('Playwright did not open a CDP session.');
    }

    $send = static fn (string $method, array $params = []) => iterator_to_array(Client::instance()->execute($sessionGuid, 'send', ['method' => $method, 'params' => (object) $params]));
    $send('WebAuthn.enable', ['enableUI' => false]);
    $send('WebAuthn.addVirtualAuthenticator', ['options' => [
        'protocol' => 'ctap2',
        'transport' => 'internal',
        'hasResidentKey' => true,
        'hasUserVerification' => true,
        'isUserVerified' => true,
        'automaticPresenceSimulation' => true,
    ]]);
}

/**
 * Layout guard for 320px / 200 % text (MVP-SPEC A.11 AC 16): the document never scrolls sideways.
 */
function assertNoHorizontalScroll(object $page, string $label = ''): void
{
    // Mobile emulation widens the LAYOUT viewport (innerWidth) to fit overflowing content, so the reference is the
    // device width (clientWidth), not innerWidth.
    $overflow = $page->script('document.documentElement.scrollWidth - document.documentElement.clientWidth');
    $offenders = $page->script(<<<'JS'
        [...document.querySelectorAll('body *')]
            .filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.right > document.documentElement.clientWidth + 1 && !e.closest('.sr-only, .tabs-scroll, nav.fixed'); })
            .map(e => e.closest('svg') ? e.closest('svg').parentElement : e).filter((e, i, all) => all.indexOf(e) === i)
            .slice(0, 6).map(e => e.tagName.toLowerCase() + '.' + String(e.className).trim().split(/\s+/).slice(0, 3).join('.') + ' "' + (e.innerText || '').trim().slice(0, 24) + '" →' + Math.round(e.getBoundingClientRect().right)).join(' | ')
        JS);
    expect($overflow)->toBeLessThanOrEqual(0, "Horizontal scroll {$label}: {$overflow}px wider than the device — {$offenders}");
}

/**
 * Emulate "system font scaling 200 %" (DESIGN-SYSTEM §2.4): the root size the browser setting would give. Every
 * size in the app is rem-based, so this is what Android "Font size: max" / iOS Larger Text does to the page.
 */
function scaleRootText(object $page, int $percent): void
{
    $page->script("document.documentElement.style.fontSize = (parseFloat(getComputedStyle(document.documentElement).fontSize) * {$percent} / 100) + 'px'");
}

/**
 * Open $url on a mobile viewport and return the LIVE, BOOTED page. (`visit()->on()->mobile()` is a factory: every
 * further call on it would open a fresh page, so the first call's result is the page the test keeps using.)
 *
 * Deterministic for the CI deploy gate (Karen, round 1: page-load timeouts on GitHub runners):
 * - the first request of a run compiles Blade views and boots the in-process server; a navigation that still
 *   times out is retried ONCE with a fresh page (GET pages only, so a retry has no side effects);
 * - the page is returned only after the document finished loading and Livewire/Alpine booted, so a test can never
 *   type into a field that Livewire's wire:model binding resets a moment later.
 *
 * $host: e.g. 'localhost' for WebAuthn (an IP address is not a valid RP ID).
 * $enforceCsp: the plugin opens every context with Playwright's bypassCSP; true makes Chromium ENFORCE the app's
 * Content-Security-Policy like a real phone does (and fire `securitypolicyviolation`, see recordCspViolations()).
 * $locale: the phone's language (e.g. 'ru-RU'; default en-US).
 */
function phone(string $url, ?string $host = null, bool $enforceCsp = false, ?string $locale = null): object
{
    $open = static function () use ($url, $host, $enforceCsp, $locale): object {
        $pending = visit($url, $enforceCsp ? ['bypassCSP' => false] : []);
        if ($host !== null) {
            $pending = $pending->withHost($host);
        }
        if ($locale !== null) {
            $pending = $pending->withLocale($locale); // the browser language → Accept-Language
        }

        return $pending->on()->mobile()->assertPathBeginsWith('/');
    };

    try {
        $page = $open();
    } catch (Throwable $e) {
        if (! isNavigationTimeout($e)) {
            throw $e;
        }
        $page = $open();
    }

    waitForBoot($page);

    return $page;
}

/**
 * Open a URL WITH a #fragment (QR /join#c=…, mail /login/confirm#t=…) the way a phone does: a real navigation from
 * another page. (The plugin's visit() rewrites the URL for its in-process server and drops the fragment.)
 */
function openWithFragment(string $pathWithFragment): object
{
    $page = phone('/privacy');
    $page->script('location.assign('.json_encode($pathWithFragment).')');
    $path = explode('#', $pathWithFragment, 2)[0];
    waitUntil($page, 'location.pathname === '.json_encode($path));
    waitForBoot($page);

    return $page;
}

/**
 * A Playwright navigation / page-load timeout (as opposed to a failed assertion).
 */
function isNavigationTimeout(Throwable $e): bool
{
    $message = $e->getMessage();

    return str_contains($message, 'Timeout') && (str_contains($message, 'exceeded') || str_contains($message, 'navigat'));
}

/**
 * Wait until the document is complete and, when it has Livewire components, Livewire has booted them.
 */
function waitForBoot(object $page, float $seconds = 15.0): void
{
    waitUntil($page, "document.readyState === 'complete' && !!window.Alpine && (!document.querySelector('[wire\\\\:id]') || (!!window.Livewire && Livewire.all().length > 0))", $seconds);
}

/**
 * Start recording CSP violations (and every <style> element added to <head>, with or without a nonce) of the
 * current document; they survive wire:navigate, which swaps the body but keeps the document. Read them back with
 * cspViolations(). Needs a page opened with phone(…, enforceCsp: true).
 */
function recordCspViolations(object $page): void
{
    $page->script(<<<'JS'
        window.__mpCsp = [];
        document.addEventListener('securitypolicyviolation', (e) => {
            window.__mpCsp.push(e.effectiveDirective + ' ' + e.blockedURI + ' ' + (e.sample || '') + ' src=' + e.sourceFile + ':' + e.lineNumber + ':' + e.columnNumber + ' target=' + (e.target && e.target.outerHTML ? e.target.outerHTML.slice(0, 200) : String(e.target)));
        });
        new MutationObserver((records) => records.forEach((r) => r.addedNodes.forEach((n) => {
            if (n.tagName === 'STYLE') {
                window.__mpCsp.push('style added ' + (n.nonce ? 'with nonce' : 'WITHOUT nonce') + ': ' + n.textContent.replace(/\s+/g, ' ').trim().slice(0, 60));
            }
        }))).observe(document.documentElement, { childList: true, subtree: true });
        JS);
}

/**
 * The CSP violations recorded since recordCspViolations() (only the violations, not the <style> log).
 *
 * @return list<string>
 */
function cspViolations(object $page): array
{
    return array_values(array_filter((array) $page->script('window.__mpCsp || []'), static fn ($line): bool => ! str_starts_with((string) $line, 'style added')));
}

/**
 * Poll a JavaScript expression until it is truthy (Page::waitForFunction of the plugin is lazy and never waits).
 */
function waitUntil(object $page, string $expression, float $seconds = 10.0): void
{
    $deadline = microtime(true) + $seconds;
    $probe = static function () use ($page, $expression): bool {
        try {
            return (bool) $page->script("!!({$expression})");
        } catch (Throwable $e) {
            // Asked while the page navigates (a Livewire redirect, a passkey login): ask the next document.
            if (str_contains($e->getMessage(), 'context was destroyed') || str_contains($e->getMessage(), 'navigat')) {
                return false;
            }
            throw $e;
        }
    };
    while (! $probe()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Timed out after {$seconds}s waiting for: {$expression}");
        }
        usleep(100_000);
    }
}

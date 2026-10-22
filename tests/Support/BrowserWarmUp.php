<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Filament\Manage\Pages\BuildingSettingsPage;
use App\Filament\Manage\Pages\HouseOpeningWizardPage;
use App\Filament\Manage\Pages\RosterImportPage;
use App\Filament\Manage\Pages\TeamPage;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Once per browser-test PROCESS, before the first browser load: pay the one-off cost of the first render of every
 * heavy page OUTSIDE every timed window (CI flake of 0.0.33: first-visit page-load timeouts on the slower runner).
 *
 * The plugin's HTTP server runs inside the single-threaded test process, so the first render of a page in a process
 * also compiles its Blade views (cold checkout: views cleared), declares the app / Filament / Livewire classes it needs
 * and fills PHP's stat / realpath caches. Measured for /b/{slug}/heating in Docker Desktop: 17–36 s cold, against
 * ~1.7 s warm — far beyond Playwright's 15 s page-load window and the 10–15 s waits of the tests, and while it runs
 * the event loop cannot answer Playwright at all. Which test is first in a process is an accident of ordering, so
 * this is done for the whole suite instead of per file:
 *
 * 1. `view:cache` compiles every view (app and vendor namespaces: Filament, Livewire, Blade icons).
 * 2. A throw-away gate-open house (heating + outages on) with a platform_admin who is also its building_admin and
 *    a resident of apartment 1 renders the resident app, the platform house page and the Filament panels once
 *    through the HTTP kernel — inside a SAVEPOINT that is rolled back, so no test ever sees that data.
 * 3. Auth guards, the session and the cache (rate limiters) are reset, so the first real browser request starts as
 *    a guest exactly as before.
 *
 * The browser loads after this measure the same warm render as every later visit; nothing is made faster by raising
 * a timeout. BROWSER_WARMUP=0 switches it off (to reproduce a cold first visit on purpose).
 */
final class BrowserWarmUp
{
    private static bool $done = false;

    public static function once(TestCase $test): void
    {
        if (self::$done || getenv('BROWSER_WARMUP') === '0') {
            return;
        }
        self::$done = true;

        Artisan::call('view:cache');

        DB::beginTransaction(); // a savepoint inside RefreshDatabase's transaction
        try {
            self::renderHeavyPages($test);
        } finally {
            DB::rollBack();
            Auth::forgetGuards();
            app('session')->driver()->flush();
            $test->flushSession();
            Cache::flush();
        }
    }

    private static function renderHeavyPages(TestCase $test): void
    {
        $slug = 'browser-warm-up';
        $b = heatingHouse($slug, 1, 2);
        $admin = makeUser('browser-warm-up@example.test', ['platform_role' => 'platform_admin']);
        grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
        member($admin, $b, 1);

        self::render($test, '/login');

        $test->actingAs($admin);
        $now = now()->getTimestamp();
        $test->withSession([StepUpService::SESSION_KEY => ['user' => $admin->id, 'at' => $now, 'seen' => $now, 'method' => 'passkey']]);

        $paths = [
            '/home', '/start', '/admin', '/admin/platform', "/admin/houses/{$slug}", "/admin/b/{$slug}",
            "/b/{$slug}", "/b/{$slug}/heating", "/b/{$slug}/water", "/b/{$slug}/me", "/b/{$slug}/parking", '/me/passkey',
            inBuilding($b, fn () => HouseOpeningWizardPage::getUrl(tenant: $b)),
            inBuilding($b, fn () => TeamPage::getUrl(tenant: $b)),
            inBuilding($b, fn () => RosterImportPage::getUrl(tenant: $b)),
            inBuilding($b, fn () => BuildingSettingsPage::getUrl(tenant: $b)),
        ];

        foreach ($paths as $path) {
            self::render($test, $path);
        }
    }

    /**
     * One GET through the kernel. Redirects, 403s and 404s (gates, module switches) still warm the stack; a 5xx is a
     * real bug and fails loudly instead of hiding behind a later timeout.
     */
    private static function render(TestCase $test, string $path): void
    {
        try {
            $status = $test->get($path)->getStatusCode();
        } catch (Throwable $e) {
            throw new RuntimeException("Browser warm-up: GET {$path} threw ".$e::class.': '.$e->getMessage(), 0, $e);
        }

        if ($status >= 500) {
            throw new RuntimeException("Browser warm-up: GET {$path} answered {$status}.");
        }
    }
}

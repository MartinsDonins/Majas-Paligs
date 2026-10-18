<?php

declare(strict_types=1);

use App\Models\Building;
use App\Tenancy\BelongsToBuilding;
use Illuminate\Support\Facades\DB;

/*
 * Arch rules (MVP-SPEC A.12 Arch/TenancyArchTest). Implemented as a source scan over app/ with comments
 * stripped (token_get_all), so static calls AND container-resolved instances
 * (`app(CurrentBuilding::class)->set()`) are caught.
 */

/**
 * @return array<string, string> relative path => code without comments
 */
function appSources(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($it as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $cache[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = $code;
    }

    return $cache;
}

/**
 * Files (outside the defining class) whose code matches $pattern.
 *
 * @return list<string>
 */
function filesMatching(string $pattern, array $exclude = []): array
{
    return array_values(array_filter(
        array_keys(appSources()),
        fn (string $path) => ! in_array($path, $exclude, true) && preg_match($pattern, appSources()[$path]) === 1,
    ));
}

it('lets only the seven allow-listed classes set or re-point the building context', function (): void {
    $pattern = '/CurrentBuilding::(set|runFor)\s*\(|CurrentBuilding::class\)\s*->\s*(set|runFor)\s*\(/';

    $allowed = [
        'app/Http/Middleware/SetCurrentBuilding.php',
        'app/Tenancy/InviteResolver.php',
        'app/Tenancy/UserScopedQuery.php',
        'app/Tenancy/BuildingIterator.php',
        'app/Tenancy/Jobs/RestoreBuildingContext.php',
        'app/Platform/BreakGlass.php',
        'app/Platform/PlatformBuildingContext.php',
    ];

    expect(array_values(array_diff(filesMatching($pattern, ['app/Tenancy/CurrentBuilding.php']), $allowed)))->toBe([]);
});

it('lifts the building scope only in the four sanctioned classes', function (): void {
    $pattern = '/withoutBuildingScope\s*\(|withoutGlobalScope\s*\(\s*BuildingScope::class|withoutGlobalScopes\s*\(|CurrentBuilding::forget\s*\(/';

    $allowed = [
        'app/Tenancy/InviteResolver.php',
        'app/Tenancy/UserScopedQuery.php',
        'app/Tenancy/BuildingIterator.php',
        'app/Platform/BreakGlass.php',
    ];

    expect(array_values(array_diff(filesMatching($pattern, ['app/Tenancy/BelongsToBuilding.php', 'app/Tenancy/CurrentBuilding.php']), $allowed)))->toBe([]);
});

it('calls UserScopedQuery::for only from the allow-listed callers', function (): void {
    $allowed = [
        'app/Livewire/Shell/BuildingSwitcher.php',
        'app/Livewire/Me/Privacy.php',
        'app/Livewire/Me/WhoViewed.php',
        'app/Gdpr/GdprExporter.php',
        'app/Gdpr/AccountEraser.php',
        'app/Auth/PinAuthService.php',
        'app/Auth/StepUpService.php',
        'app/Http/Controllers/Auth/PostLoginRedirect.php',
        'app/Models/User.php',
    ];

    expect(array_values(array_diff(filesMatching('/UserScopedQuery::for\s*\(/', ['app/Tenancy/UserScopedQuery.php']), $allowed)))->toBe([]);
});

it('uses UserScopedQuery in User only inside getTenants / canAccessTenant / getDefaultTenant / canAccessPanel', function (): void {
    $code = appSources()['app/Models/User.php'];
    preg_match_all('/function\s+(\w+)\s*\([^{]*\{((?:[^{}]|\{(?2)\})*)\}/s', $code, $m, PREG_SET_ORDER);

    $users = array_map(fn ($f) => $f[1], array_filter($m, fn ($f) => str_contains($f[2], 'UserScopedQuery::for')));

    expect(array_values(array_diff($users, ['getTenants', 'canAccessTenant', 'getDefaultTenant', 'canAccessPanel'])))->toBe([]);
});

it('makes every tenant model use BelongsToBuilding, but not Building itself', function (): void {
    $tenantTables = collect(DB::select(
        "SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'building_id' AND is_nullable = 'NO'"
    ))->pluck('table_name')->reject(fn ($t) => in_array($t, ['section_land_parcel', 'building_counters', 'audit_entries'], true))->all();

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        $model = new $class;
        $uses = in_array(BelongsToBuilding::class, class_uses_recursive($class), true);

        if (in_array($model->getTable(), $tenantTables, true)) {
            expect($uses)->toBeTrue("{$class} must use BelongsToBuilding");
        }
    }

    expect(in_array(BelongsToBuilding::class, class_uses_recursive(Building::class), true))->toBeFalse();
});

it('reads env() only inside config files', function (): void {
    expect(filesMatching('/(?<![\w>:$])env\s*\(/'))->toBe([]);
});

it('ships no dd() / dump() / var_dump() / ray()', function (): void {
    expect(filesMatching('/(?<![\w>:$])(dd|dump|var_dump|ray)\s*\(/'))->toBe([]);
});

it('uses pgsql_retention only in prune commands and pgsql_owner nowhere in app/', function (): void {
    $retention = filesMatching('/pgsql_retention/');

    expect(array_filter($retention, fn ($p) => ! str_starts_with($p, 'app/Console/Commands/Prune/')))->toBe([])
        ->and(filesMatching('/pgsql_owner/'))->toBe([]);
});

it('declares strict types in every app file', function (): void {
    $missing = array_keys(array_filter(appSources(), fn ($code) => ! str_contains($code, 'declare(strict_types=1)')));

    expect($missing)->toBe([]);
});

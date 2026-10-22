<?php

declare(strict_types=1);

use App\Audit\AuditLogger;
use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Platform\PlatformPurpose;
use Filament\Facades\Filament;

/*
 * Audit pages render dynamic keys: admin.audit.purpose.{PlatformPurpose}, audit.actor.{actor_role} and
 * audit.action.{action}. The static-key parity test cannot see them, so a new purpose / actor role / action used to
 * show up as a raw key on /admin/platform/audit (Karen, round 3). Every value the code can write must have an LV and
 * an RU string.
 */

/**
 * @return array<string, string>
 */
function auditLang(string $file): array
{
    return json_decode((string) file_get_contents(lang_path($file)), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * All JSON strings of a locale as the translator merges them (lang/, admin/, roster/, start/ — AppServiceProvider).
 *
 * @return array<string, string>
 */
function auditLangMerged(string $locale): array
{
    return array_merge(...array_map(static fn (string $dir): array => auditLang("{$dir}{$locale}.json"), ['', 'admin/', 'roster/', 'start/']));
}

/**
 * Every PHP source under app/ as [path => contents].
 *
 * @return array<string, string>
 */
function auditSources(): array
{
    $sources = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

/**
 * Every literal actor role the code passes as `actorRole:` (ternaries included), the role enum values the helper
 * methods return, and the renderer's own fallback "system".
 *
 * @return list<string>
 */
function auditedActorRoles(): array
{
    $roles = ['system', 'self', ...array_map(static fn (BuildingRoleType $r): string => $r->value, BuildingRoleType::cases())];
    foreach (auditSources() as $source) {
        preg_match_all('/actorRole:\s*(.+?)(?:,\s*[a-zA-Z]+:|\)\s*;|\)\s*,?\s*$)/m', $source, $m);
        foreach ($m[1] as $expression) {
            preg_match_all("/'([a-z_]+)'/", $expression, $literals);
            $roles = [...$roles, ...$literals[1]];
        }
    }

    return array_values(array_unique($roles));
}

it('labels every platform purpose in LV and RU', function (string $locale): void {
    $lang = auditLangMerged($locale);
    $missing = array_values(array_filter(
        array_map(static fn (PlatformPurpose $p): string => 'admin.audit.purpose.'.$p->value, PlatformPurpose::cases()),
        static fn (string $key): bool => ! isset($lang[$key]),
    ));

    expect($missing)->toBe([]);
})->with(['lv', 'ru']);

it('labels every audited actor role in LV and RU', function (string $locale): void {
    $roles = auditedActorRoles();
    $lang = auditLangMerged($locale);

    expect($roles)->toContain('platform_admin_mcp', 'console', 'platform_admin')
        ->and(array_values(array_filter($roles, static fn (string $r): bool => ! isset($lang['audit.actor.'.$r]))))->toBe([]);
})->with(['lv', 'ru']);

it('labels every literal audit action in LV and RU', function (string $locale): void {
    $actions = [];
    foreach (auditSources() as $source) {
        preg_match_all("/AuditLogger::record\\(\\s*(?:action:\\s*)?'([a-z_]+\\.[a-z_.]+)'/", $source, $m);
        preg_match_all("/AuditLogger::record\\(\\s*\\$[a-z]+\\s*\\?\\s*'([a-z_.]+)'\\s*:\\s*'([a-z_.]+)'/", $source, $t);
        $actions = [...$actions, ...$m[1], ...$t[1], ...$t[2]];
    }
    // A literal ending in "_" / "." is a prefix completed at runtime (e.g. 'riser.dispute_'.$decision->value).
    $actions = array_values(array_unique(array_filter($actions, static fn (string $a): bool => ! str_ends_with($a, '_') && ! str_ends_with($a, '.'))));
    $lang = auditLangMerged($locale);

    expect(count($actions))->toBeGreaterThan(20)
        ->and(array_values(array_filter($actions, static fn (string $a): bool => ! isset($lang['audit.action.'.$a]))))->toBe([]);
})->with(['lv', 'ru']);

it('renders the round-3 purposes and the MCP actor on the platform audit page without raw keys', function (string $locale): void {
    $platform = makeUser("audit-labels-{$locale}@example.test", ['platform_role' => 'platform_admin']);
    $platform->forceFill(['locale' => $locale])->save();
    $this->actingAs($platform);
    Filament::setCurrentPanel(Filament::getPanel('platform'));
    app(StepUpService::class)->markFresh($platform, 'totp');
    $b = makeBuilding("audit-labels-{$locale}", 1, 1);

    foreach ([PlatformPurpose::HouseOverview, PlatformPurpose::SelfLink, PlatformPurpose::OpeningReview] as $purpose) {
        AuditLogger::record('platform.building_context', $b, data: ['purpose' => $purpose->value, 'building_id' => (int) $b->id], platform: true, actorRole: 'platform_admin');
    }
    foreach ([PlatformPurpose::McpRead, PlatformPurpose::McpWrite] as $purpose) {
        AuditLogger::record('platform.building_context', $b, data: ['purpose' => $purpose->value, 'building_id' => (int) $b->id], platform: true, actorRole: 'platform_admin_mcp');
    }

    $html = (string) $this->get('/admin/platform/audit')->assertOk()->getContent();
    $lang = auditLangMerged($locale);

    expect($html)->toContain('lang="'.$locale.'"')
        ->not->toContain('admin.audit.purpose.')
        ->not->toContain('audit.actor.')
        ->not->toContain('audit.action.')
        ->toContain(e($lang['admin.audit.purpose.house_overview']))
        ->toContain(e($lang['admin.audit.purpose.self_link']))
        ->toContain(e($lang['admin.audit.purpose.opening_review']))
        ->toContain(e($lang['admin.audit.purpose.mcp_read']))
        ->toContain(e($lang['admin.audit.purpose.mcp_write']))
        ->toContain(e($lang['audit.actor.platform_admin_mcp']));
})->with(['lv', 'ru']);

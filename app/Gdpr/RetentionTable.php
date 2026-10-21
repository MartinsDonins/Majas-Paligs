<?php

declare(strict_types=1);

namespace App\Gdpr;

/**
 * The ONE source of retention periods shown to people (ROUND2-SPEC §3.4 `{retention.table}`, §5.5): the building
 * privacy notice, the DPA annex B and the opening wizard render this table, and RetentionTableTest checks every row
 * against the prune command that enforces it — so the notice, PLANNING §5 and the database can never drift.
 *
 * Each row: key (lang `retention.row.<key>` = what is kept), period key (lang `retention.period.<key>`), the modules
 * the row belongs to (a notice lists only the rows of the modules it covers), and the enforcing command + the SQL
 * interval fragment that command must contain (null = enforced outside a prune command, e.g. backups rotation).
 */
final class RetentionTable
{
    /**
     * @var list<array{key: string, period: string, modules: list<string>, command: ?string, interval: ?string}>
     */
    public const ROWS = [
        ['key' => 'login_tokens', 'period' => 'one_day', 'modules' => ['core'], 'command' => 'PruneLoginTokens', 'interval' => "interval '1 day'"],
        ['key' => 'unconfirmed_signups', 'period' => 'seven_days', 'modules' => ['core'], 'command' => 'PruneUsers', 'interval' => "interval '7 days'"],
        ['key' => 'role_invitations', 'period' => 'thirty_days_after_closing', 'modules' => ['core'], 'command' => 'PruneRoleInvitations', 'interval' => "interval '30 days'"],
        ['key' => 'heating_reports', 'period' => 'season_plus_month', 'modules' => ['heating', 'outages'], 'command' => 'PruneHeatingReports', 'interval' => 'addMonth()'],
        ['key' => 'audit_log', 'period' => 'twenty_four_months', 'modules' => ['core'], 'command' => 'PruneAuditEntries', 'interval' => '{--months=24}'],
        ['key' => 'sighting_photos', 'period' => 'thirty_days', 'modules' => ['parking'], 'command' => 'PruneSightings', 'interval' => "interval '30 days'"],
        ['key' => 'sightings', 'period' => 'twelve_months', 'modules' => ['parking'], 'command' => 'PruneSightings', 'interval' => "interval '12 months'"],
        ['key' => 'plate_lookups', 'period' => 'six_months', 'modules' => ['parking'], 'command' => 'PrunePlateLookups', 'interval' => "interval '6 months'"],
        ['key' => 'vehicles', 'period' => 'thirty_days_after_end', 'modules' => ['parking'], 'command' => 'PruneVehicles', 'interval' => "interval '30 days'"],
        ['key' => 'roster', 'period' => 'until_moved_out', 'modules' => ['roster'], 'command' => null, 'interval' => null],
        ['key' => 'import_files', 'period' => 'twenty_four_hours', 'modules' => ['roster', 'parking'], 'command' => null, 'interval' => null],
        ['key' => 'backups', 'period' => 'thirty_days', 'modules' => ['core'], 'command' => null, 'interval' => null],
    ];

    /**
     * Rows for the given modules (all rows when null).
     *
     * @param  list<string>|null  $modules
     * @return list<array{key: string, period: string, modules: list<string>, command: ?string, interval: ?string}>
     */
    public static function rows(?array $modules = null): array
    {
        if ($modules === null) {
            return self::ROWS;
        }

        return array_values(array_filter(self::ROWS, static fn (array $r): bool => array_intersect($r['modules'], $modules) !== []));
    }

    /**
     * Markdown list for `{retention.table}` in the given locale ("- Ieejas kodi: 1 diena").
     *
     * @param  list<string>|null  $modules
     */
    public static function markdown(string $locale, ?array $modules = null): string
    {
        return implode("\n", array_map(
            static fn (array $r): string => '- '.__('retention.row.'.$r['key'], locale: $locale).': '.__('retention.period.'.$r['period'], locale: $locale),
            self::rows($modules),
        ));
    }
}

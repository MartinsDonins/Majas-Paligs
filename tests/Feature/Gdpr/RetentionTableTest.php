<?php

declare(strict_types=1);

use App\Enums\DocumentKind;
use App\Enums\Locale;
use App\Gdpr\RetentionTable;
use App\Models\DocumentTemplate;
use Database\Seeders\DocumentTemplateSeeder;

/*
 * RetentionTable is the ONE source of the periods people read (ROUND2-SPEC §3.4 {retention.table}, §5.5): every row
 * that a prune command enforces must match that command, and the notice / DPA masters render it instead of typing
 * periods by hand — so the notice and the database can never drift.
 */

it('matches every row to the interval its prune command enforces', function (): void {
    foreach (RetentionTable::ROWS as $row) {
        if ($row['command'] === null) {
            continue;
        }

        $source = (string) file_get_contents(app_path('Console/Commands/Prune/'.$row['command'].'.php'));
        expect(str_contains($source, (string) $row['interval']))->toBeTrue($row['key'].' → '.$row['command']);
    }
});

it('has a label and a period in LV and RU for every row', function (): void {
    foreach (RetentionTable::ROWS as $row) {
        foreach (['lv', 'ru'] as $locale) {
            expect(__('retention.row.'.$row['key'], locale: $locale))->not->toBe('retention.row.'.$row['key'])
                ->and(__('retention.period.'.$row['period'], locale: $locale))->not->toBe('retention.period.'.$row['period']);
        }
    }
});

it('lists only the rows of the covered modules', function (): void {
    $core = RetentionTable::markdown('lv', ['core', 'heating', 'outages']);
    $parking = RetentionTable::markdown('lv', ['core', 'parking']);

    expect($core)->toContain(__('retention.row.audit_log', locale: 'lv'))->not->toContain(__('retention.row.plate_lookups', locale: 'lv'))
        ->and($parking)->toContain(__('retention.row.plate_lookups', locale: 'lv').': '.__('retention.period.six_months', locale: 'lv'));
});

it('is rendered by the notice and DPA masters instead of hand-typed periods', function (): void {
    (new DocumentTemplateSeeder)->run();

    foreach ([DocumentKind::PrivacyNotice, DocumentKind::Dpa, DocumentKind::DpaAnnex] as $kind) {
        foreach (Locale::cases() as $locale) {
            expect(DocumentTemplate::latest($kind, $locale)->body)->toContain('{retention.table}');
        }
    }
});

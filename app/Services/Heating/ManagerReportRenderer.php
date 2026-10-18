<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Gdpr\CsvWriter;
use App\Models\ManagerReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a manager report FROM ITS STORED SNAPSHOT only (what was exported is what is stored): A4 PDF
 * (DejaVu Sans, black-and-white safe, DESIGN-SYSTEM §8) and a formula-injection-safe CSV.
 */
final class ManagerReportRenderer
{
    public function pdf(ManagerReport $report, string $locale = 'lv'): Response
    {
        $pdf = Pdf::loadView('pdf.manager-report', [
            'r' => $report->snapshot,
            'locale' => in_array($locale, ['lv', 'ru'], true) ? $locale : 'lv',
        ])->setPaper('a4', 'portrait');

        return $pdf->download($this->filename($report, 'pdf'))->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function csv(ManagerReport $report, string $locale = 'lv'): Response
    {
        return response(CsvWriter::toString($this->csvRows($report, $locale)), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($report, 'csv').'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @return list<list<scalar|null>>
     */
    public function csvRows(ManagerReport $report, string $locale = 'lv'): array
    {
        $s = $report->snapshot;
        $t = static fn (string $key, array $r = []): string => __('report.'.$key, $r, $locale);

        $rows = [
            [$t('csv.number'), $s['number'] ?? $report->number],
            [$t('csv.building'), $s['building']['name'] ?? ''],
            [$t('csv.period'), ($s['period']['from'] ?? '').' — '.($s['period']['to'] ?? '')],
            [$t('csv.sections'), implode(', ', $s['sections'] ?? [])],
            [$t('csv.sharing_floors'), ($s['sharing']['heating_floors'] ?? false) ? $t('yes') : $t('no')],
            [$t('csv.sharing_numbers'), ($s['sharing']['apartment_numbers'] ?? false) ? $t('yes') : $t('no')],
            [],
        ];

        if (($s['kind'] ?? 'heating') === 'heating') {
            $header = [$t('csv.riser'), $t('csv.section'), $t('csv.status'), $t('csv.confirmed'), $t('csv.apartments'), $t('csv.floors_covered'), $t('csv.coverage'), $t('csv.cold'), $t('csv.lukewarm'), $t('csv.warm'), $t('csv.overheated'), $t('csv.whole_riser'), $t('csv.confidence')];
            $withFloors = (bool) ($s['sharing']['heating_floors'] ?? false);
            $withNumbers = (bool) ($s['sharing']['apartment_numbers'] ?? false);
            if ($withFloors) {
                $header[] = $t('csv.floors_reported');
            }
            if ($withNumbers) {
                $header[] = $t('csv.apartment_numbers');
            }
            $rows[] = $header;

            foreach ($s['risers'] ?? [] as $r) {
                $row = [
                    $r['code'], $r['section'], __('enum.aggregate_status.'.$r['status'], [], $locale),
                    $r['confirmed'] ? $t('yes') : $t('no'), $r['distinct_apartments'], $r['floors_covered'], $r['coverage_total'],
                    $r['status_counts']['cold'] ?? 0, $r['status_counts']['lukewarm'] ?? 0, $r['status_counts']['warm'] ?? 0,
                    $r['status_counts']['overheated'] ?? 0, $r['whole_riser_under'] ? $t('yes') : $t('no'),
                    __('enum.riser_confidence.'.$r['confidence'], [], $locale),
                ];
                if ($withFloors) {
                    $row[] = implode(' ', $r['floors_reported'] ?? []);
                }
                if ($withNumbers) {
                    $row[] = implode(' ', $r['apartment_numbers'] ?? []);
                }
                $rows[] = $row;
            }

            $rows[] = [];
            $rows[] = [$t('triage.title')];
            foreach (($s['triage'] ?? []) as $key => $count) {
                $rows[] = [$t('triage.'.$key), $count];
            }
            $rows[] = [$t('excluded_disputed'), $s['excluded_disputed'] ?? 0];
        } else {
            $rows[] = [$t('csv.kind'), $t('csv.scope'), $t('csv.status'), $t('csv.started'), $t('csv.expected_end'), $t('csv.resolved'), $t('csv.confirmations'), $t('csv.manager_ref')];
            foreach ($s['incidents'] ?? [] as $i) {
                $rows[] = [
                    __('enum.incident_kind.'.$i['kind'], [], $locale),
                    $this->scopeText($i['scope'], $locale),
                    __('enum.incident_status.'.$i['status'], [], $locale),
                    $i['started_at'], $i['expected_end_at'], $i['resolved_at'], $i['confirmations'], $i['manager_ref'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array{type: string, section: ?int, riser: ?string, position: ?int}  $scope
     */
    public function scopeText(array $scope, string $locale = 'lv'): string
    {
        return match ($scope['type']) {
            'riser' => __('outage.scope.riser', ['code' => (string) $scope['riser']], $locale),
            'col' => __('outage.scope.column', ['section' => (string) $scope['section'], 'position' => (string) $scope['position']], $locale),
            'section' => __('outage.scope.section', ['n' => (string) $scope['section']], $locale),
            default => __('outage.scope.building', [], $locale),
        };
    }

    private function filename(ManagerReport $report, string $ext): string
    {
        return strtolower($report->number).'.'.$ext;
    }
}

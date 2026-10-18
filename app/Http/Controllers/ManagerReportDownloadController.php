<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\Building;
use App\Models\ManagerReport;
use App\Models\User;
use App\Services\Heating\ManagerReportRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /b/{building}/reports/{report}.{pdf|csv} — signed 10-min URL + step-up (route middleware) and
 * ManagerReportPolicy::download: building_admin any report, section_elder only when the report's section_ids ⊆
 * their own stairwells. The file is rendered from the stored snapshot; every download is audit-logged.
 * A report of another building is invisible through the building scope (404).
 */
final class ManagerReportDownloadController
{
    public function __invoke(Request $request, Building $building, string $report, string $format, ManagerReportRenderer $renderer): Response
    {
        /** @var User $user */
        $user = $request->user();

        $model = ManagerReport::query()->findOrFail($report);
        Gate::forUser($user)->authorize('download', $model);

        AuditLogger::record('manager_report.download', $model, data: ['report_number' => $model->number, 'format' => $format]);

        $locale = app()->getLocale();

        return $format === 'csv' ? $renderer->csv($model, $locale) : $renderer->pdf($model, $locale);
    }

    /**
     * Signed (10 min) download URL.
     */
    public static function url(Building $building, ManagerReport $report, string $format = 'pdf'): string
    {
        return URL::temporarySignedRoute(
            'building.manager-report',
            now()->addMinutes((int) config('majas.exports.signed_minutes', 10)),
            ['building' => $building->slug, 'report' => $report->id, 'format' => $format],
        );
    }
}

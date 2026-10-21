<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Gdpr\CsvWriter;
use App\Models\Building;
use App\Models\User;
use App\Services\Import\ImportRefused;
use App\Services\Import\ImportRunService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /admin/b/{building}/files/import-template/{kind}.csv (ROUND2-SPEC §4.7) — the import template generated at
 * runtime in the user's language: header row + one obviously synthetic example row (no phone- or plate-shaped string
 * is tracked in git). UTF-8 BOM + `;` so it opens correctly in Excel (LV) and Google Sheets. No personal data, so it
 * works below the gate; roster: building_admin + elders, permits: parking staff (ImportHandler::mayDownloadTemplate).
 */
final class ImportTemplateController
{
    public function __invoke(Request $request, Building $building, string $kind): Response
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $handler = app(ImportRunService::class)->handler($kind);
        } catch (ImportRefused) {
            abort(404);
        }

        abort_unless($handler->mayDownloadTemplate($user), 403);

        $template = $handler->template(app()->getLocale());

        return response(CsvWriter::toString([$template['headers'], $template['example']], ';'), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.__('import.template.filename.'.$kind).'.csv"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}

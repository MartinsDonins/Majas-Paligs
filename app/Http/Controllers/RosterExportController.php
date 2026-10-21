<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\User;
use App\Services\Roster\RosterActionFailed;
use App\Services\Roster\RosterExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /admin/b/{building}/files/roster-export (signed 10 min) — the whole roster as CSV, generated now and never
 * stored (ROUND2-SPEC §5.3): building_admin + a strong step-up of THIS session (policy `export`), 5 per building per
 * day, audited `roster.export` {rows}.
 */
final class RosterExportController
{
    public function __invoke(Request $request, Building $building): Response
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $export = app(RosterExporter::class)->csv($user, app()->getLocale());
        } catch (RosterActionFailed $e) {
            abort($e->key === 'export_cap' ? 429 : 403, $e->label());
        }

        return response($export['content'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.__('roster.export.filename').'-'.now('Europe/Riga')->format('Y-m-d').'.csv"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}

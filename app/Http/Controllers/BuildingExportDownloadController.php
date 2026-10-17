<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Enums\ExportStatus;
use App\Models\Building;
use App\Models\BuildingExport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /b/{building}/files/exports/{export} — signed 10 min, building_admin + step-up; audit-logged.
 */
final class BuildingExportDownloadController
{
    public function __invoke(Request $request, Building $building, string $export): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('export', $building);

        $row = BuildingExport::query()->whereKey($export)->first();
        abort_unless($row !== null && $row->status === ExportStatus::Ready && $row->path !== null && $row->expires_at?->isFuture(), 404);

        AuditLogger::record('building.export_downloaded', $row);

        return Storage::disk((string) config('majas.exports.disk', 'local'))->download(
            $row->path,
            'majas-dati-'.$building->slug.'-'.now('Europe/Riga')->format('Y-m-d').'.zip',
            ['Cache-Control' => 'no-store, private'],
        );
    }
}

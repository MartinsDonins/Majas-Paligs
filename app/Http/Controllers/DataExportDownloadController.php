<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Enums\ExportStatus;
use App\Models\DataExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /me/exports/{export} — signed (10 min), owner only, not expired. The signature never reaches logs
 * (nginx mp_noargs, Sentry scrubber).
 */
final class DataExportDownloadController
{
    public function __invoke(Request $request, DataExport $export): StreamedResponse
    {
        abort_unless($export->user_id === $request->user()?->id, 404);
        abort_unless($export->status === ExportStatus::Ready && $export->path !== null && $export->expires_at?->isFuture(), 404);

        AuditLogger::record('gdpr.export_downloaded', $export, [(int) $export->user_id], platform: true, actorRole: 'self');

        return Storage::disk((string) config('majas.exports.disk', 'local'))->download(
            $export->path,
            'majas-paligs-mani-dati-'.now('Europe/Riga')->format('Y-m-d').'.zip',
            ['Cache-Control' => 'no-store, private'],
        );
    }
}

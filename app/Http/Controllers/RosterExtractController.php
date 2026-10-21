<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\ResidentContact;
use App\Models\User;
use App\Services\Roster\RosterActionFailed;
use App\Services\Roster\RosterExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /admin/b/{building}/files/roster-extract/{contact} (signed 10 min) — Art. 15 answer for ONE roster contact: a
 * ZIP with contact.json + contact.csv + the roster audit rows about it. Admin or the contact's stairwell elder with a
 * fresh second factor (policy `reveal`); audited `roster.subject_export`. Another stairwell's contact → 404.
 */
final class RosterExtractController
{
    public function __invoke(Request $request, Building $building, string $contact): Response
    {
        /** @var User $user */
        $user = $request->user();

        $row = ResidentContact::query()->whereKey($contact)->first();
        abort_if($row === null || ! $user->can('view', $row), 404);

        try {
            $zip = app(RosterExporter::class)->extract($user, $row, app()->getLocale());
        } catch (RosterActionFailed $e) {
            abort(403, $e->label());
        }

        return response($zip, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.__('roster.extract.filename').'-'.now('Europe/Riga')->format('Y-m-d').'.zip"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}

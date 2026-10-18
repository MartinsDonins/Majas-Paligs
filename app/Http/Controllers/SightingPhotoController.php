<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\Building;
use App\Models\Sighting;
use App\Models\User;
use App\Services\Parking\SightingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /b/{building}/files/sightings/{sighting}/photo — 5-minute signed URL + step-up (route middleware) and
 * SightingPolicy::photo (parking_officer / building_admin, gate L1). Streams the re-encoded JPEG from the private
 * disk with no-store; a sighting of another building is invisible through the building scope (404). Audit-logged.
 */
final class SightingPhotoController
{
    public function __invoke(Request $request, Building $building, string $sighting): Response
    {
        /** @var User $user */
        $user = $request->user();

        $model = Sighting::query()->findOrFail($sighting);
        Gate::forUser($user)->authorize('photo', $model);
        abort_unless($model->hasPhoto() && SightingService::disk()->exists((string) $model->photo_path), 404);

        AuditLogger::record('sighting.photo_view', $model);

        return response((string) SightingService::disk()->get((string) $model->photo_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="sighting.jpg"',
        ]);
    }
}

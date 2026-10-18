<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\ParkingExport;
use App\Models\User;
use App\Services\Parking\ParkingExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /b/{building}/files/parking-exports/{export} — 10-minute signed URL + step-up and ParkingExportPolicy::download
 * (requester or approver, approved, not expired, gate L1). The file is generated now and never stored.
 */
final class ParkingExportDownloadController
{
    public function __invoke(Request $request, Building $building, string $export, ParkingExportService $exports): Response
    {
        /** @var User $user */
        $user = $request->user();

        $model = ParkingExport::query()->findOrFail($export);
        Gate::forUser($user)->authorize('download', $model);

        return $exports->download($user, $model, app()->getLocale());
    }
}

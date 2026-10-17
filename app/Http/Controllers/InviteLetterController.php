<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApartmentInvite;
use App\Models\Building;
use App\Models\User;
use App\Services\Invites\InviteLetterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /b/{building}/files/invite-letters/{batch} — signed 10-min URL, building_admin + step-up (route
 * middleware), InvitePolicy::print (gate L-core open). Single use: the batch spec is pulled from the cache.
 */
final class InviteLetterController
{
    public function __invoke(Request $request, Building $building, string $batch, InviteLetterService $letters): Response
    {
        /** @var User $user */
        $user = $request->user();

        Gate::forUser($user)->authorize('print', ApartmentInvite::class);

        $response = $letters->render($user, $batch);
        abort_if($response === null, 404);

        return $response;
    }
}

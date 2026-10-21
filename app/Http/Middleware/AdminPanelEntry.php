<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Filament's Authenticate for the building panel, plus one rule (ROUND2-SPEC §1.1): the bare panel root `/admin/b`
 * for a signed-in person without any building role (e.g. a platform_admin who administers no house) goes back to the
 * /admin chooser instead of a 403. Every other URL keeps Filament's checks unchanged (canAccessPanel → 403,
 * canAccessTenant → 404). A subclass, not a separate middleware: Authenticate is sorted first by the middleware
 * priority list, so a middleware placed "before" it would never run in time.
 */
final class AdminPanelEntry extends Authenticate
{
    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && $request->is('admin/b') && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            throw new HttpResponseException(redirect()->route('admin.entry'));
        }

        parent::authenticate($request, $guards);
    }
}

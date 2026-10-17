<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a login with an elder-issued temporary code, `/login/pin/new` (and logout) is the only reachable route
 * until a new PIN is set (MVP-SPEC A.6 / A.6a).
 */
final class EnsurePinChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->pin_must_change
            && ! $request->routeIs('login.pin.new', 'logout', 'locale.set')
            && ! $request->is('livewire*', 'up')) {
            return redirect()->route('login.pin.new');
        }

        return $next($request);
    }
}

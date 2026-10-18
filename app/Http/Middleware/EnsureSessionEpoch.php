<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionEpoch;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session whose epoch is older than `users.auth_epoch` (a PIN / TOTP / role / device change happened
 * elsewhere). Runs on the web group, both Filament panels and every /livewire/update (persistent middleware).
 * A session without an epoch (fresh "remember this phone" login, or a session older than this check) adopts the
 * current one — a remember cookie is only accepted while its token matches, and every bump rotates the token.
 */
final class EnsureSessionEpoch
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $current = (int) $user->auth_epoch;

        if (! $session->has(SessionEpoch::SESSION_KEY)) {
            $session->put(SessionEpoch::SESSION_KEY, $current);

            return $next($request);
        }

        if ((int) $session->get(SessionEpoch::SESSION_KEY) === $current) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();

        if ($request->expectsJson() || $request->is('livewire*')) {
            abort(401);
        }

        return redirect()->route('login');
    }
}

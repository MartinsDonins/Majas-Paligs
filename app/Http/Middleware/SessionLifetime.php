<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\RememberCookieGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comfortable sessions that respect "Atcerēties šo telefonu" (QA 2026-10-20 "izmet ārā", ADR 0006). Runs BEFORE
 * StartSession, which reads the lifetime when it builds the Redis handler and writes the cookie.
 *
 * - A device that carries the remember cookie keeps its session for `majas.session.remembered_minutes` (7 days) of
 *   inactivity. Before, every session ended after 120 idle minutes: the remember cookie signed the person back in, but
 *   the open form got a CSRF 419 ("This page has expired"), the step-up was gone and admins had to type a code again.
 *   The remember cookie itself (180 days, rotated on every credential change) already grants that much, so a longer
 *   session on the same device adds no new bearer.
 * - Without the remember cookie (a shared phone): the session cookie ends with the browser and the session after
 *   SESSION_LIFETIME (120) idle minutes, as before.
 */
final class SessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->cookies->has(RememberCookieGuard::COOKIE)) {
            config([
                'session.lifetime' => max((int) config('session.lifetime'), (int) config('majas.session.remembered_minutes', 10080)),
                'session.expire_on_close' => false,
            ]);
        } else {
            config(['session.expire_on_close' => true]);
        }

        return $next($request);
    }
}

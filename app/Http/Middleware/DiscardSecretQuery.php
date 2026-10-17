<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `discard.query`: pages that receive bearer secrets only via the URL fragment answer any query string
 * (legacy `?c=CODE`, `?t=TOKEN`, …) with an immediate 303 to the bare path. The value is never read, logged
 * or echoed (MVP-SPEC A.6 / A.6a).
 */
final class DiscardSecretQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->getQueryString() !== null) {
            return redirect($request->getPathInfo(), 303);
        }

        return $next($request);
    }
}

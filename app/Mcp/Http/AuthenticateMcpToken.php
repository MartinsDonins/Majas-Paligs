<?php

declare(strict_types=1);

namespace App\Mcp\Http;

use App\Mcp\McpAccess;
use App\Mcp\McpTokenService;
use App\Support\Identifiers;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer authentication for POST /mcp (docs/MCP.md, ADR 0008). No session, no cookie: only
 * `Authorization: Bearer kmcp_…`. Unknown, malformed, wrong, revoked, expired and stale tokens all get the same 401
 * (laravel/mcp adds `WWW-Authenticate: Bearer realm="mcp", error="invalid_token"`). 10 failed attempts per 15 minutes
 * per client bucket (/24, /48) → 429 before any lookup.
 */
final class AuthenticateMcpToken
{
    public const FAILURES_PER_15_MIN = 10;

    public function __construct(
        private readonly McpTokenService $tokens,
        private readonly McpAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $failKey = 'mcp-auth-fail:'.Identifiers::ipBucket((string) $request->ip());

        if (RateLimiter::tooManyAttempts($failKey, self::FAILURES_PER_15_MIN)) {
            return $this->error(429, 'Too many failed MCP authentication attempts. Wait 15 minutes.')
                ->header('Retry-After', (string) RateLimiter::availableIn($failKey));
        }

        $presented = (string) $request->bearerToken();
        $token = $presented === '' ? null : $this->tokens->resolve($presented);

        if ($token === null) {
            RateLimiter::hit($failKey, 15 * 60);

            return $this->error(401, 'Unauthenticated: a valid Kaimiņos MCP token is required.');
        }

        $user = $token->user;
        $this->access->authenticate($token, $user);
        // Request-scoped only: the web guard is never logged in, so no session or remember cookie is created.
        Auth::guard('web')->setUser($user);
        $request->attributes->set('mcp_token_id', $token->id);
        $this->tokens->touch($token);

        return $next($request);
    }

    private function error(int $status, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => $status === 429 ? -32029 : -32001, 'message' => $message],
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}

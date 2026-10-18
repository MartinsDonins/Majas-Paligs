<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /up → {status, version} (global rule: version visible in API responses; Docker HEALTHCHECK).
 */
final class HealthController
{
    /**
     * GET /up/ip → the caller's OWN address, truncated like the access log (IPv4 /24, IPv6 /48). Deployment smoke
     * test for the proxy chain: from a known client the prefix must be that client's, never a Cloudflare edge or
     * the Docker network (docs/SECURITY.md "Client IP behind Cloudflare").
     */
    public function ip(Request $request): JsonResponse
    {
        $ip = (string) $request->ip();
        $packed = @inet_pton($ip);

        if ($packed !== false && strlen($packed) === 16) {
            $prefix = (string) inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10)).'/48';
        } elseif ($packed !== false) {
            $prefix = implode('.', array_slice(explode('.', $ip), 0, 3)).'.0/24';
        } else {
            $prefix = '-';
        }

        return response()->json(['ip_prefix' => $prefix])->header('Cache-Control', 'no-store, private');
    }

    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $status = 'ok';
        } catch (Throwable) {
            $status = 'degraded';
        }

        return response()->json(
            ['status' => $status, 'version' => (string) config('app.version')],
            $status === 'ok' ? 200 : 503,
        );
    }
}

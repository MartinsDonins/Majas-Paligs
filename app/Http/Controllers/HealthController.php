<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /up → {status, version} (global rule: version visible in API responses; Docker HEALTHCHECK).
 */
final class HealthController
{
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

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers (PLANNING §5 A02, SEC-004, MVP-SPEC A.6a).
 *
 * Resident app: strict nonce CSP + Livewire's CSP-safe Alpine build (no 'unsafe-eval').
 * Filament panels (/admin/b/…, /admin/platform/…): Filament 5 renders inline scripts without a nonce and Alpine
 * expressions that need eval → 'unsafe-inline' 'unsafe-eval' for those paths only. TRACKED DEBT (docs/SECURITY.md).
 * Everywhere: HSTS, frame-ancestors none, Permissions-Policy, Referrer-Policy no-referrer, X-App-Version.
 */
final class SecurityHeaders
{
    /** Paths that must never be cached (secrets travel through these pages). */
    private const NO_STORE = ['join', 'join/*', 'login', 'login/*', 'step-up', 'me/exports/*', 'admin', 'admin/*', 'team-invite/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $panel = $this->isPanel($request);

        if (! $panel) {
            Vite::useCspNonce();
            config(['livewire.csp_safe' => true]);
        }

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-App-Version', (string) config('app.version'));
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');

        if ($request->isSecure() || app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->csp($panel));
        }

        if ($request->is(...self::NO_STORE)) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function isPanel(Request $request): bool
    {
        // Livewire update responses are JSON: the page's own CSP applies to them.
        // The /admin chooser itself is a resident-shell page (strict CSP); only the two panels below it are Filament.
        return $request->is('admin/b', 'admin/b/*', 'admin/platform', 'admin/platform/*', 'filament/*');
    }

    private function csp(bool $panel): string
    {
        $dev = app()->isLocal() ? ' http://localhost:5173 ws://localhost:5173' : '';

        $script = $panel
            ? "'self' 'unsafe-inline' 'unsafe-eval'".$dev
            : "'self' 'nonce-".Vite::cspNonce()."'".$dev;

        $style = $panel ? "'self' 'unsafe-inline'".$dev : "'self' 'nonce-".Vite::cspNonce()."'".$dev;

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src {$style}",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'".$dev,
            "manifest-src 'self'",
            "worker-src 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'none'",
            "object-src 'none'",
        ]);
    }
}

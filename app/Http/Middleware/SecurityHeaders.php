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
 * Resident app: strict nonce CSP + Livewire's CSP-safe Alpine build (no 'unsafe-eval'); style-src also allows the exact
 * sha256 of Livewire's own style block (wire:navigate, see livewireStyleHashes()).
 * Filament panels (/admin/b/…, /admin/platform/…): Filament 5 renders inline scripts without a nonce and Alpine
 * expressions that need eval → 'unsafe-inline' 'unsafe-eval' for those paths only. TRACKED DEBT (docs/SECURITY.md).
 * Everywhere: HSTS, frame-ancestors none, Permissions-Policy, Referrer-Policy no-referrer, X-App-Version.
 * Behind the container nginx, HSTS / X-Frame-Options / X-Content-Type-Options / Referrer-Policy are owned by
 * docker/nginx/security.conf (it hides these copies with fastcgi_hide_header, so each is sent once); the values here
 * must stay identical to it (tests/Feature/Security/SecurityHeadersOnceTest.php) for runs without nginx.
 */
final class SecurityHeaders
{
    /** Paths that must never be cached (secrets travel through these pages). */
    private const NO_STORE = ['join', 'join/*', 'login', 'login/*', 'step-up', 'me/exports/*', 'admin', 'admin/*', 'team-invite/*', 'mcp'];

    public function handle(Request $request, Closure $next): Response
    {
        $panel = $this->isPanel($request);

        // Set both ways: in a long-lived process (the browser-test server, a queue worker rendering views, Octane) a
        // resident request must not leave the CSP-safe Alpine build switched on for the next panel request.
        config(['livewire.csp_safe' => ! $panel]);
        if (! $panel) {
            Vite::useCspNonce();
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
            $headers->set('Content-Security-Policy', $this->csp($panel, $panel ? [] : self::livewireStyleHashes($response)));
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

    /**
     * sha256 sources for Livewire's own <style data-livewire-style> block of THIS response (Karen, round 1).
     *
     * wire:navigate fetches the next page and parses it with DOMParser BEFORE it swaps the nonces (Livewire 4's
     * view-transition check, transitionPageSwap). Chromium checks that parsed document against the CURRENT page's
     * policy, so the new page's Livewire style — carrying the NEXT response's nonce — was reported as a
     * `style-src-elem` violation on every navigation. Allowing the exact hash of that one block (static text: Livewire's
     * wire:loading / x-cloak rules + the configured progress-bar colour) removes the violation without
     * 'unsafe-inline' and without trusting any other inline style. The hash is taken from the served bytes, so it can
     * never drift from what Livewire renders.
     *
     * @return list<string>
     */
    public static function livewireStyleHashes(Response $response): array
    {
        if (! str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return [];
        }

        $content = $response->getContent();
        if (! is_string($content) || ! str_contains($content, 'data-livewire-style')
            || preg_match_all('/<style\b[^>]*\bdata-livewire-style\b[^>]*>(.*?)<\/style>/s', $content, $matches) < 1) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $css): string => "'sha256-".base64_encode(hash('sha256', $css, true))."'",
            $matches[1],
        )));
    }

    /**
     * @param  list<string>  $styleHashes
     */
    private function csp(bool $panel, array $styleHashes = []): string
    {
        $dev = app()->isLocal() ? ' http://localhost:5173 ws://localhost:5173' : '';

        $script = $panel
            ? "'self' 'unsafe-inline' 'unsafe-eval'".$dev
            : "'self' 'nonce-".Vite::cspNonce()."'".$dev;

        $hashes = $styleHashes !== [] ? ' '.implode(' ', $styleHashes) : '';
        $style = $panel ? "'self' 'unsafe-inline'".$dev : "'self' 'nonce-".Vite::cspNonce()."'".$hashes.$dev;

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

<?php

declare(strict_types=1);

namespace App\Support;

use Livewire\Livewire;

/**
 * Same-origin "return to this page" paths (language toggle, step-up, post-login deep links).
 *
 * Never trust url()->previous() alone: StartSession records EVERY non-AJAX GET the browser makes with the session
 * cookie — the service worker's /sw.js update check, the manifest, a 404 favicon — so the LV/RU toggle used to send
 * people to the raw /sw.js JavaScript (QA 2026-10-20). Those URLs are not pages and are refused here; the PWA routes
 * are also session-less now (routes/web.php).
 */
final class SafeReturn
{
    /** Exact paths that are fetched by the browser itself, never a page a person was looking at. */
    private const NOT_PAGES = ['/sw.js', '/manifest.webmanifest', '/up', '/up/ip', '/favicon.ico', '/robots.txt'];

    /** Path prefixes that are endpoints or static files. */
    private const NOT_PAGE_PREFIXES = ['/livewire', '/locale/', '/build/', '/icons/', '/storage/', '/fonts/', '/vendor/', '/filament/'];

    /**
     * A safe relative path (with query, never a fragment) or null. Accepts a relative path or an absolute URL of
     * THIS application; refuses other hosts, protocol-relative and backslash tricks, control characters and
     * non-page endpoints.
     */
    public static function path(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '' || strlen($candidate) > 2048) {
            return null;
        }

        $candidate = explode('#', $candidate, 2)[0];
        $root = rtrim(url('/'), '/');
        if ($candidate === $root) {
            $candidate = '/';
        } elseif (str_starts_with($candidate, $root.'/') || str_starts_with($candidate, $root.'?')) {
            $candidate = substr($candidate, strlen($root));
            $candidate = str_starts_with($candidate, '?') ? '/'.$candidate : $candidate;
        }

        if (! str_starts_with($candidate, '/') || str_starts_with($candidate, '//')
            || str_contains($candidate, '\\') || preg_match('/[\x00-\x1F\x7F\s]/', $candidate) === 1) {
            return null;
        }

        $path = explode('?', $candidate, 2)[0];
        if (in_array(rtrim($path, '/') === '' ? '/' : rtrim($path, '/'), self::NOT_PAGES, true)) {
            return null;
        }
        foreach (self::NOT_PAGE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return null;
            }
        }

        return $candidate;
    }

    /**
     * The page being rendered right now, as a relative path: during a Livewire update the ORIGINAL page (Livewire
     * memo), otherwise the request URI with its query.
     */
    public static function current(): string
    {
        if (Livewire::isLivewireRequest()) {
            return self::path('/'.ltrim((string) Livewire::originalPath(), '/')) ?? '/';
        }

        return self::path(request()->getRequestUri()) ?? '/';
    }
}

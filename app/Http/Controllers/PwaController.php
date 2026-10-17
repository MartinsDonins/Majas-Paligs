<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * PWA manifest (LV/RU names follow the UI locale) and the root-scoped service worker built by vite-plugin-pwa
 * into public/build/sw.js (static assets only; HTML and /livewire/* are NetworkOnly).
 */
final class PwaController
{
    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => __('common.app_name'),
            'short_name' => __('common.app_name'),
            'description' => __('landing.subtitle'),
            'lang' => app()->getLocale(),
            'start_url' => '/home',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#F7F6F3',
            'theme_color' => '#0B4F55',
            'icons' => [
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icons/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => asset('icons/icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    public function serviceWorker(): Response
    {
        $path = public_path('build/sw.js');
        $body = is_file($path) ? (string) file_get_contents($path) : '/* service worker not built */';

        return response($body, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache',
        ]);
    }
}

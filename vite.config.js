import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

// Mājas Palīgs — Vite build (Tailwind 4, self-hosted Inter, PWA with Workbox).
// Service worker policy (DESIGN-SYSTEM §4/§7, PLANNING §4.1): only hashed static build assets are precached.
// HTML pages and /livewire/* are NetworkOnly — no offline queue, no stale pages, nothing personal cached.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
        VitePWA({
            injectRegister: null,
            registerType: 'autoUpdate',
            filename: 'sw.js',
            scope: '/',
            base: '/',
            buildBase: '/build/',
            manifest: false, // served by Laravel at /manifest.webmanifest (LV/RU names, version)
            workbox: {
                globPatterns: ['**/*.{js,css,woff2}'],
                globIgnores: ['**/theme-*.css'], // admin-panel CSS is not precached for residents
                // The worker is served from /sw.js (root scope): precache URLs must point into /build/.
                modifyURLPrefix: { '': '/build/' },
                inlineWorkboxRuntime: true,
                navigateFallback: null,
                cleanupOutdatedCaches: true,
                runtimeCaching: [
                    { urlPattern: ({ request }) => request.mode === 'navigate', handler: 'NetworkOnly' },
                    { urlPattern: ({ url }) => url.pathname.startsWith('/livewire'), handler: 'NetworkOnly' },
                ],
            },
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: { host: 'localhost' },
        watch: {
            usePolling: process.env.VITE_POLLING === '1',
            interval: 300,
            ignored: ['**/storage/framework/views/**', '**/vendor/**', '**/DOC/**'],
        },
    },
});

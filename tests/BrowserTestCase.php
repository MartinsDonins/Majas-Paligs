<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;

/**
 * Real-browser tests (tests/Browser, pest-plugin-browser): the page must load the REAL Vite build (Alpine
 * components, CSS), so the withoutVite() stub of the HTTP-level suite is undone. Assets come from public/build —
 * scripts/dev.sh browser runs `npm run build` first.
 */
abstract class BrowserTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withVite();

        // Livewire stores temporary uploads on the `tmp-for-tests` disk while running unit tests.
        config(['filesystems.disks.tmp-for-tests' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/tmp-for-tests')]]);

        Event::listen(RouteMatched::class, fn (RouteMatched $event) => self::reparseMultipartFiles($event->request));
    }

    /**
     * Harness shim (test-only; production requests never pass through here). The plugin's in-process HTTP server
     * splits multipart bodies with mb_* string functions, which turns non-UTF-8 bytes into "?" (a JPEG arrives as
     * 3F3F3F3F…), and keys files by the literal field name ("files[]") instead of nesting them like PHP-FPM.
     * Rebuild $request->files from the untouched raw body, byte-safe and nested, so Livewire uploads see exactly
     * what a real server would hand them.
     */
    private static function reparseMultipartFiles(Request $request): void
    {
        $type = (string) $request->headers->get('content-type');
        if (! str_starts_with(strtolower($type), 'multipart/form-data') || preg_match('/boundary="?([^";]+)"?/i', $type, $b) !== 1) {
            return;
        }

        $files = [];
        foreach (explode('--'.$b[1], (string) $request->getContent()) as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }
            [$headers, $body] = array_pad(explode("\r\n\r\n", $part, 2), 2, '');
            if (str_ends_with($body, "\r\n")) {
                $body = substr($body, 0, -2);
            }
            if (preg_match('/;\s*name="([^"]*)"/', $headers, $name) !== 1 || preg_match('/filename="([^"]*)"/', $headers, $filename) !== 1) {
                continue;
            }
            $path = (string) tempnam(sys_get_temp_dir(), 'mp-upload-');
            file_put_contents($path, $body);
            $mime = preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $t) === 1 ? trim($t[1]) : null;
            $file = new UploadedFile($path, $filename[1], $mime, null, true);

            if (str_ends_with($name[1], '[]')) {
                $files[substr($name[1], 0, -2)][] = $file;
            } else {
                $files[$name[1]] = $file;
            }
        }

        if ($files !== []) {
            $request->files->replace($files);
        }
    }
}

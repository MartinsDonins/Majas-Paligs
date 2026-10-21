<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/*
 * Karen, round 1: each wire:navigate logged a CSP `style-src-elem` violation. Livewire parses the next page with
 * DOMParser before it swaps the nonces, so the next page's <style data-livewire-style> (with the NEXT nonce) was
 * checked against the current policy. style-src now allows the exact sha256 of that block — and nothing else.
 * The real-browser proof with an ENFORCED policy is tests/Browser/CspNavigateSmokeTest.
 */
function styleSrc(string $csp): string
{
    preg_match('/(?:^|;\s*)style-src ([^;]*)/', $csp, $m);

    return $m[1] ?? '';
}

it('allows exactly the sha256 of the Livewire style block served on the page, keeping the strict nonce policy', function (): void {
    $response = $this->get('/login')->assertOk();
    $html = (string) $response->getContent();

    expect(preg_match('/<style\b[^>]*data-livewire-style[^>]*>(.*?)<\/style>/s', $html, $m))->toBe(1);
    $hash = "'sha256-".base64_encode(hash('sha256', $m[1], true))."'";

    $style = styleSrc((string) $response->headers->get('Content-Security-Policy'));
    expect($style)->toContain("'nonce-")
        ->toContain($hash)
        ->not->toContain('unsafe-inline')
        ->and(substr_count($style, "'sha256-"))->toBe(1);
});

it('adds no hash where Livewire renders no style block, and leaves the Filament panel policy as it was', function (): void {
    $plain = new Response('<html><head><style>body{color:red}</style></head></html>', 200, ['Content-Type' => 'text/html']);
    $json = new Response('{"html":"<style data-livewire-style>x</style>"}', 200, ['Content-Type' => 'application/json']);

    expect(SecurityHeaders::livewireStyleHashes($plain))->toBe([])
        ->and(SecurityHeaders::livewireStyleHashes($json))->toBe([]);

    $up = (string) $this->get('/up')->headers->get('Content-Security-Policy');
    expect(styleSrc($up))->not->toContain("'sha256-");

    // Filament panels keep their tracked 'unsafe-inline' debt (SEC-004.1) and get no hash.
    $panel = (string) $this->get('/admin/platform')->headers->get('Content-Security-Policy');
    expect(styleSrc($panel))->toContain("'unsafe-inline'")->not->toContain("'sha256-");
});

it('uses no Livewire transitions, whose injected <style> carries no nonce and no stable hash', function (): void {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $f): bool => str_contains((string) file_get_contents($f->getPathname()), 'wire:transition'))
        ->map(fn (SplFileInfo $f): string => $f->getFilename())
        ->values()->all();

    expect($offenders)->toBe([]);
});

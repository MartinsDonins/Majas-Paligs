<?php

declare(strict_types=1);

it('returns {status, version} on /up with the version header', function (): void {
    $version = trim((string) file_get_contents(base_path('VERSION')));

    $this->get('/up')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'version' => $version])
        ->assertHeader('X-App-Version', $version);
});

it('shows the version in the footer of public pages', function (string $path): void {
    $version = trim((string) file_get_contents(base_path('VERSION')));

    $this->get($path)
        ->assertOk()
        ->assertSee('data-testid="app-version"', false)
        ->assertSee($version)
        ->assertHeader('X-App-Version', $version);
})->with(['/', '/join', '/login', '/login/pin', '/privacy', '/terms']);

it('serves the PWA manifest and keeps the version visible to resident pages', function (): void {
    $this->get('/manifest.webmanifest')->assertOk()->assertJsonPath('start_url', '/home');
});

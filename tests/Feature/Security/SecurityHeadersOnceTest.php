<?php

declare(strict_types=1);

/*
 * Each security header reaches the browser exactly once (round 3: production sent Strict-Transport-Security twice,
 * once from App\Http\Middleware\SecurityHeaders and once from the container nginx's add_header, which never replaces
 * an upstream header).
 */

/**
 * @return array{add: array<string, string>, hide: list<string>, merge: bool}
 */
function nginxSecurityConf(): array
{
    $conf = (string) file_get_contents(base_path('docker/nginx/security.conf'));
    $conf = (string) preg_replace('/#.*$/m', '', $conf); // comments only; no header value contains '#'

    preg_match_all('/^\s*add_header\s+(\S+)\s+"([^"]*)"\s+always\s*;/m', $conf, $add, PREG_SET_ORDER);
    preg_match_all('/^\s*fastcgi_hide_header\s+(\S+)\s*;/m', $conf, $hide);

    $headers = [];
    foreach ($add as [, $name, $value]) {
        expect($headers)->not->toHaveKey(strtolower($name), "add_header {$name} appears twice in security.conf");
        $headers[strtolower($name)] = $value;
    }

    return [
        'add' => $headers,
        'hide' => array_map('strtolower', $hide[1]),
        'merge' => preg_match('/^\s*add_header_inherit\s+merge\s*;/m', $conf) === 1,
    ];
}

it('hides the PHP-FPM copy of every header nginx adds, so nginx sends each one once', function (): void {
    $nginx = nginxSecurityConf();

    expect($nginx['add'])->toHaveKeys(['strict-transport-security', 'x-frame-options', 'x-content-type-options', 'referrer-policy'])
        ->and($nginx['add']['strict-transport-security'])->toBe('max-age=31536000; includeSubDomains');

    foreach (array_keys($nginx['add']) as $name) {
        expect(in_array($name, $nginx['hide'], true))->toBeTrue("nginx adds {$name} but does not fastcgi_hide_header the app's copy");
    }
    // Static-file locations of the base image declare their own add_header; merge keeps these headers there too.
    expect($nginx['merge'])->toBeTrue();
});

it('sends HSTS once from the app, with the same value nginx enforces', function (): void {
    $response = $this->get('https://localhost/privacy')->assertOk();
    $nginx = nginxSecurityConf()['add'];

    foreach ($nginx as $name => $value) {
        expect($response->headers->all($name))->toBe([$value], "{$name} from the app");
    }
});

it('sends HSTS in production even when TLS ends at the proxy', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $response = $this->get('/up');

    expect($response->headers->all('strict-transport-security'))->toBe(['max-age=31536000; includeSubDomains']);
});

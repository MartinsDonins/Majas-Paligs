<?php

declare(strict_types=1);

use App\Auth\RememberCookieGuard;

it('names the session cookie __Host-mp_session with Secure, path / and no domain', function (): void {
    expect(config('session.cookie'))->toBe('__Host-mp_session')
        ->and(config('session.secure'))->toBeTrue()
        ->and(config('session.path'))->toBe('/')
        ->and(config('session.domain'))->toBeNull()
        ->and(config('session.same_site'))->toBe('lax');
});

it('uses the __Host-mp_remember cookie for "remember this phone"', function (): void {
    $user = makeUser('remember@example.test');

    $guard = auth()->guard('web');
    expect($guard)->toBeInstanceOf(RememberCookieGuard::class)
        ->and($guard->getRecallerName())->toBe('__Host-mp_remember');

    $guard->login($user, true);
    $cookie = collect(app('cookie')->getQueuedCookies())->first(fn ($c) => $c->getName() === '__Host-mp_remember');

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->getDomain())->toBeNull();
});

it('never sets an XSRF-TOKEN cookie', function (string $path): void {
    $response = $this->get($path);

    expect(collect($response->headers->getCookies())->map->getName()->all())->not->toContain('XSRF-TOKEN');
})->with(['/', '/join', '/login', '/login/pin']);

it('sends the security headers', function (): void {
    $this->get('/')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Permissions-Policy');

    $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
    expect($csp)->toContain("frame-ancestors 'none'")
        ->toContain("'nonce-")
        ->not->toContain('unsafe-eval');
});

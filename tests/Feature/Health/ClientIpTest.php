<?php

declare(strict_types=1);

use App\Auth\PinAuthService;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Review fix (drošība, 0.0.12): the client IP comes from REMOTE_ADDR as resolved by nginx's realip module — never from
 * X-Forwarded-For (its right-most entry behind Cloudflare → Traefik is a Cloudflare edge shared by every visitor).
 */
it('ignores X-Forwarded-For when resolving the client IP (realip in nginx is the only resolver)', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
        ->withHeader('X-Forwarded-For', '198.51.100.1, 162.158.10.20')
        ->getJson('/up/ip')
        ->assertOk()
        ->assertExactJson(['ip_prefix' => '203.0.113.0/24'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('truncates IPv6 to /48 in the smoke endpoint', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:abcd:12::7'])->getJson('/up/ip')
        ->assertExactJson(['ip_prefix' => '2001:db8:abcd::/48']);
});

it('keys the PIN IP limiter on the real client, so two visitors behind one Cloudflare edge do not share a bucket', function (): void {
    $edge = '162.158.10.20';

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->withHeader('X-Forwarded-For', $edge)->get('/login/pin')->assertOk();
    expect(request()->ip())->toBe('203.0.113.5');

    app(PinAuthService::class)->attempt('9-NONE', '000001', (string) request()->ip());
    expect(RateLimiter::attempts('pin-ip:v4:203.0.113.5'))->toBe(1)
        ->and(RateLimiter::attempts('pin-ip:v4:'.$edge))->toBe(0);
});

it('still honours X-Forwarded-Proto from the TLS-terminating proxy', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.3'])->withHeader('X-Forwarded-Proto', 'https')->get('/up');
    expect(request()->isSecure())->toBeTrue();
});

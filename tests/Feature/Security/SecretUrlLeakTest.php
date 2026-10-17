<?php

declare(strict_types=1);

use App\Livewire\Auth\EmailLogin;
use App\Livewire\Onboarding\RedeemInvite;
use App\Logging\RedactSecrets;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Services\Invites\InviteCodeService;
use App\Services\Invites\InviteLetterService;
use App\Support\SentryScrubber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Sentry\Breadcrumb;
use Sentry\Event;

/*
 * A.6a / AC 19: invite codes, magic-link tokens and signatures never travel in URLs that reach logs.
 */
it('answers GET /join?c=… with 303 to /join without echoing the value', function (): void {
    $response = $this->get('/join?c=ABCDE-23456');

    $response->assertStatus(303)->assertHeader('Location', url('/join'));
    expect($response->getContent())->not->toContain('ABCDE-23456');
});

it('answers legacy magic-link URLs with 303 and never echoes the token', function (): void {
    $this->get('/login/confirm/abc.secret.token')->assertStatus(303)->assertHeader('Location', url('/login/confirm'));
    expect($this->get('/login/confirm?t=abc.secret.token')->getContent())->not->toContain('abc.secret.token');
});

it('puts the invite QR payload and the mail link into URL fragments', function (): void {
    Notification::fake();
    makeUser('frag@example.test');
    Livewire::test(EmailLogin::class)->set('email', 'frag@example.test')->call('send');

    Notification::assertSentTo(User::query()->get(), MagicLoginLink::class, fn (MagicLoginLink $n) => str_contains($n->url(), '/login/confirm#t=') && ! str_contains($n->url(), '?'));

    $method = new ReflectionMethod(InviteLetterService::class, 'qr');
    expect($method->getParameters()[0]->getAttributes(SensitiveParameter::class))->not->toBeEmpty();
});

it('sends Referrer-Policy no-referrer and no-store on secret-bearing pages', function (string $path): void {
    $this->get($path)->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
})->with(['/join', '/login', '/login/confirm', '/login/pin']);

it('keeps codes and tokens out of the Laravel log during a full join + login flow', function (): void {
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = json_encode([$message->message, $message->context]);
    });

    $b = makeBuilding('leak-a', 1, 1, open: true);
    $code = makeInvite($b, 1);
    Livewire::test(RedeemInvite::class)->set('code', $code)->call('submit');
    Log::info('request finished', ['url' => 'https://x.test/join?c='.$code, 't' => 'secret-token', 'signature' => 'sig']);

    $all = implode("\n", $logged);
    $redacted = RedactSecrets::scrub(['url' => 'https://x.test/join?c='.$code, 't' => 'secret-token', 'signature' => 'sig']);

    expect($redacted['url'])->toBe('https://x.test/join')
        ->and($redacted['t'])->toBe('[redacted]')
        ->and($redacted['signature'])->toBe('[redacted]')
        ->and(RedactSecrets::scrubString('GET /login/confirm/abc123 /me/exports/01J?signature=x'))->toBe('GET /login/confirm/[redacted] /me/exports/[redacted]');
    expect($all)->not->toContain(app(InviteCodeService::class)->format($code));
});

it('scrubs Sentry events and breadcrumbs (query, fragment, secret keys, token paths)', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://kaimini.example/login/confirm/abc?t=secret#t=frag',
        'query_string' => 't=secret',
        'data' => ['code' => 'ABCDE-23456', 'pin' => '123456', 'email' => 'x@example.test'],
        'headers' => ['Referer' => 'https://x/join#c=ABC', 'Cookie' => 'a=b', 'Accept' => 'text/html'],
    ]);

    $scrubbed = SentryScrubber::beforeSend($event)->getRequest();

    expect($scrubbed['url'])->toBe('https://kaimini.example/login/confirm/[redacted]')
        ->and($scrubbed)->not->toHaveKey('query_string')
        ->and($scrubbed['data']['code'])->toBe('[redacted]')
        ->and($scrubbed['data']['pin'])->toBe('[redacted]')
        ->and($scrubbed['headers'])->not->toHaveKey('Referer')
        ->and($scrubbed['headers'])->not->toHaveKey('Cookie')
        ->and(config('sentry.send_default_pii'))->toBeFalse();

    $crumb = SentryScrubber::beforeBreadcrumb(new Breadcrumb('info', 'http', 'navigation', 'to /join?c=X', ['to' => '/join?c=ABC', 'from' => '/me/exports/01J?signature=s']));
    expect($crumb->getMetadata())->toBe(['to' => '/join', 'from' => '/me/exports/[redacted]']);
});

it('ships an nginx config that logs mp_noargs without query strings or referers', function (): void {
    $conf = (string) file_get_contents(base_path('docker/nginx/nginx.conf.template'));
    $format = (string) preg_replace('/^.*log_format\s+mp_noargs\s+([^;]+);.*$/s', '$1', $conf);

    expect($conf)->toContain('log_format mp_noargs')
        ->toContain('access_log  $NGINX_ACCESS_LOG mp_noargs')
        ->and($format)->not->toContain('$request ')
        ->not->toContain('$request_uri')
        ->not->toContain('$args')
        ->not->toContain('$http_referer')
        ->not->toContain('$remote_addr');

    expect((string) file_get_contents(base_path('docker/nginx/security.conf')))->toContain('Referrer-Policy           "no-referrer"');
});

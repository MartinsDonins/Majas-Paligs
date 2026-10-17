<?php

declare(strict_types=1);

use App\Livewire\Auth\EmailLogin;
use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function sentLink(): MagicLoginLink
{
    $sent = null;
    Notification::assertSentTo(User::query()->whereNotNull('email')->get(), MagicLoginLink::class, function (MagicLoginLink $n) use (&$sent): bool {
        $sent = $n;

        return true;
    });

    return $sent;
}

beforeEach(fn () => Notification::fake());

it('puts the token in the URL fragment, never in a query or path', function (): void {
    makeUser('anna@example.test');
    Livewire::test(EmailLogin::class)->set('email', 'anna@example.test')->call('send')->assertSet('sent', true);

    $url = sentLink()->url();

    expect($url)->toContain('/login/confirm#t=')
        ->and(parse_url($url, PHP_URL_QUERY))->toBeNull()
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/login/confirm');
});

it('does not consume the token on GET, consumes it once on POST', function (): void {
    $user = makeUser('anna@example.test');
    Livewire::test(EmailLogin::class)->set('email', 'anna@example.test')->call('send');
    $token = substr(sentLink()->url(), strpos(sentLink()->url(), '#t=') + 3);

    $this->get('/login/confirm')->assertOk()->assertSee(__('auth.confirm.title'));
    expect(LoginToken::query()->whereNull('used_at')->count())->toBe(1);

    $this->post('/login/confirm', ['t' => $token])->assertRedirect(route('home.redirect'));
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    $this->post('/login/confirm', ['t' => $token])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('expires tokens after 15 minutes', function (): void {
    makeUser('anna@example.test');
    Livewire::test(EmailLogin::class)->set('email', 'anna@example.test')->call('send');
    $token = substr(sentLink()->url(), strpos(sentLink()->url(), '#t=') + 3);

    $this->travel(16)->minutes();

    $this->post('/login/confirm', ['t' => $token])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('answers unknown e-mails identically and sends nothing', function (): void {
    makeUser('anna@example.test');

    $known = Livewire::test(EmailLogin::class)->set('email', 'anna@example.test')->call('send');
    $unknown = Livewire::test(EmailLogin::class)->set('email', 'nobody@example.test')->call('send');

    $strip = fn (string $html) => preg_replace(['/wire:snapshot="[^"]*"/', '/wire:id="[^"]*"/', '/(anna|nobody)@example\.test/'], '', $html);

    expect($strip($known->html()))->toBe($strip($unknown->html()));
    Notification::assertSentTimes(MagicLoginLink::class, 1);
});

it('throttles 3 requests per 15 minutes per e-mail', function (): void {
    makeUser('anna@example.test');

    for ($i = 0; $i < 3; $i++) {
        Livewire::test(EmailLogin::class)->set('email', 'anna@example.test')->call('send')->assertHasNoErrors();
    }

    Livewire::test(EmailLogin::class)->set('email', 'anna@example.test')->call('send')->assertHasErrors('email');
    Notification::assertSentTimes(MagicLoginLink::class, 3);
});

it('answers a legacy path token with 303 to the bare route without echoing it', function (): void {
    $this->get('/login/confirm/SECRET-TOKEN-VALUE')
        ->assertStatus(303)
        ->assertRedirect('/login/confirm');

    $this->get('/login/confirm?t=SECRET-TOKEN-VALUE')->assertStatus(303)->assertHeader('Location', url('/login/confirm'));
});

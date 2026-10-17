<?php

declare(strict_types=1);

use App\Livewire\Auth\EmailLogin;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(fn () => Notification::fake());

function mailedCode(): string
{
    $code = null;
    Notification::assertSentTo(User::query()->whereNotNull('email')->get(), MagicLoginLink::class, function (MagicLoginLink $n) use (&$code): bool {
        $code = $n->code();

        return true;
    });

    return $code;
}

it('logs in with the 6-digit code from the same mail (single use)', function (): void {
    $user = makeUser('code@example.test');
    Livewire::test(EmailLogin::class)->set('email', 'code@example.test')->call('send');
    $code = mailedCode();

    $this->post('/login/code', ['email' => 'code@example.test', 'code' => substr($code, 0, 3).' '.substr($code, 3)])
        ->assertRedirect(route('home.redirect'));
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    $this->post('/login/code', ['email' => 'code@example.test', 'code' => $code])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('burns the token after 5 wrong attempts', function (): void {
    makeUser('code@example.test');
    Livewire::test(EmailLogin::class)->set('email', 'code@example.test')->call('send');
    $code = mailedCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login/code', ['email' => 'code@example.test', 'code' => $wrong]);
    }

    $this->post('/login/code', ['email' => 'code@example.test', 'code' => $code])->assertRedirect(route('login'));
    $this->assertGuest();
});

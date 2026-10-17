<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Auth\MagicLinkService;
use App\Support\Identifiers;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Ieiet ar e-pastu" (AUTH-001): always the same response for known and unknown addresses; throttled
 * 3/15 min per e-mail and 10/h per IP. The mail carries the link (fragment token) and a 6-digit code.
 */
#[Layout('layouts::guest')]
final class EmailLogin extends Component
{
    public string $email = '';

    public bool $remember = true;

    public bool $sent = false;

    public function mount(): void
    {
        $this->email = (string) session('login_email', '');
        $this->sent = in_array(session('status'), ['code_invalid'], true) && $this->email !== '';
    }

    public function send(MagicLinkService $magic): void
    {
        $this->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']], [
            'email.required' => __('auth.email.invalid'),
            'email.email' => __('auth.email.invalid'),
        ]);

        $email = mb_strtolower(trim($this->email));
        $key = 'magic-email:'.Identifiers::emailHmac($email);
        $ipKey = 'magic-ip:'.Identifiers::ipBucket((string) request()->ip());

        if (RateLimiter::tooManyAttempts($key, (int) config('majas.magic_link.per_email_15min', 3))
            || RateLimiter::tooManyAttempts($ipKey, (int) config('majas.magic_link.per_ip_hour', 10))) {
            $this->addError('email', __('auth.email.cooldown'));

            return;
        }

        RateLimiter::hit($key, 15 * 60);
        RateLimiter::hit($ipKey, 3600);

        $magic->request($email, request()->ip());

        $this->email = $email;
        $this->sent = true;
    }

    public function again(): void
    {
        $this->sent = false;
    }

    public function render(): View
    {
        return view('livewire.auth.email-login')->title(__('auth.login'));
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Auth\LoginCompleter;
use App\Auth\PinAuthService;
use App\Livewire\Concerns\AssertsPasskey;
use App\Models\Passkey;
use App\Models\User;
use App\Support\Identifiers;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Bez e-pasta — ar PIN" (AUTH-003): handle (apt-XXXX) + 6-digit PIN. Every failure kind shows the SAME
 * message; the calm cooldown screen is driven only by the IP/device counter. A resident who added a passkey in
 * "Ieeja un drošība" can use it here as well (ADR 0006).
 */
#[Layout('layouts::guest')]
final class PinLogin extends Component
{
    use AssertsPasskey;

    public string $handle = '';

    public string $pin = '';

    public bool $remember = true;

    public bool $failed = false;

    public bool $cooldown = false;

    public int $cooldownMinutes = 0;

    public function login(PinAuthService $pins, LoginCompleter $completer): ?RedirectResponse
    {
        $handle = $this->handle;
        $pin = $this->pin;
        $this->pin = '';
        $this->failed = false;

        if (trim($handle) === '' || $pin === '') {
            $this->failed = true;

            return null;
        }

        $result = $pins->attempt($handle, $pin, (string) request()->ip());

        if ($result->status === 'cooldown') {
            $this->cooldown = true;
            $this->cooldownMinutes = max(1, (int) ceil(RateLimiter::availableIn('pin-ip:'.Identifiers::ipBucket((string) request()->ip())) / 60));

            return null;
        }

        if (! $result->ok() || $result->user === null) {
            $this->failed = true;

            return null;
        }

        $response = $completer->login(request(), $result->user, $this->remember, 'pin');

        return redirect()->to($response->getTargetUrl());
    }

    protected function passkeyUser(): null
    {
        return null;
    }

    protected function passkeyVerified(Passkey $passkey, User $owner): void
    {
        $response = app(LoginCompleter::class)->login(request(), $owner, $this->remember, 'passkey');

        $this->redirect($response->getTargetUrl());
    }

    public function render(): View
    {
        return view('livewire.auth.pin-login')->title(__('auth.pin.title'));
    }
}

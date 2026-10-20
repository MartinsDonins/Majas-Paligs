<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Auth\LoginCompleter;
use App\Auth\PinAuthService;
use App\Support\Identifiers;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Bez e-pasta — ar PIN" (AUTH-003): handle (apt-XXXX) + 6-digit PIN. Every failure kind shows the SAME
 * message; the calm cooldown screen is driven only by the IP/device counter.
 */
#[Layout('layouts::guest')]
final class PinLogin extends Component
{
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

    public function render(): View
    {
        return view('livewire.auth.pin-login')->title(__('auth.pin.title'));
    }
}

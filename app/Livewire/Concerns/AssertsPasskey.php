<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Auth\Passkeys\PasskeyService;
use App\Models\Passkey;
use App\Models\User;
use App\Support\Identifiers;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Renderless;

/**
 * "Ieiet ar pirkstu / Face ID" — the browser half lives in resources/js/app.js (Alpine `passkeyAuth`, CSP-safe):
 * it asks passkeyOptions(), runs navigator.credentials.get() via @simplewebauthn/browser and sends the result to
 * passkeyAssert(). The using component decides what a verified passkey means (login, step-up).
 * Throttled per device bucket (IP /24, /48): 120 option requests (the login page asks once per load for the autofill) and 10 failed assertions per 15 minutes.
 */
trait AssertsPasskey
{
    /** The user the passkey must belong to (step-up), or null for a login (discoverable passkeys). */
    abstract protected function passkeyUser(): ?User;

    abstract protected function passkeyVerified(Passkey $passkey, User $owner): void;

    #[Renderless]
    public function passkeyOptions(PasskeyService $passkeys): ?string
    {
        $key = 'passkey-options:'.Identifiers::ipBucket((string) request()->ip());

        if (RateLimiter::tooManyAttempts($key, 120)) {
            return null;
        }
        RateLimiter::hit($key, 15 * 60);

        return $passkeys->assertionOptions($this->passkeyUser());
    }

    public function passkeyAssert(PasskeyService $passkeys, string $response = ''): void
    {
        $key = 'passkey-fail:'.Identifiers::ipBucket((string) request()->ip());

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('passkey', __('auth.passkey.cooldown', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]));

            return;
        }

        $expected = $this->passkeyUser();
        $passkey = strlen($response) <= 16_384 ? $passkeys->verifyAssertion($response, $expected) : null;
        $owner = $passkey !== null ? User::query()->find($passkey->authenticatable_id) : null;

        if ($passkey === null || $owner === null) {
            RateLimiter::hit($key, 15 * 60);
            $this->addError('passkey', __('auth.passkey.failed'));

            return;
        }

        $this->passkeyVerified($passkey, $owner);
    }
}

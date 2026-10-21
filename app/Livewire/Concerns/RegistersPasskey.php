<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Auth\Passkeys\PasskeyService;
use App\Auth\StepUpService;
use App\Models\Passkey;
use App\Models\User;
use Livewire\Attributes\Renderless;

/**
 * "Izveidot ieejas atslēgu" — the browser half is Alpine `passkeyRegister` in resources/js/app.js. Adding a passkey
 * follows the same rule as the authenticator app (StepUpService::mayAddFactor): with an existing second factor a
 * fresh MFA of THIS session, otherwise a fresh first-factor proof (e-mail step-up or a login < 15 min ago). Without it
 * the person is sent to the step-up first and comes back here.
 *
 * A successful registration (user verification required) proves the new authenticator, so it counts as this
 * session's passkey step-up — one tap instead of a code right after (same rule as a first TOTP enrolment).
 */
trait RegistersPasskey
{
    /** The page to come back to after a step-up that was needed before adding a passkey. */
    abstract protected function passkeyReturnTo(): string;

    abstract protected function passkeyRegistered(Passkey $passkey, User $user): void;

    #[Renderless]
    public function passkeyRegisterOptions(PasskeyService $passkeys, StepUpService $stepUp): ?string
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $stepUp->mayAddFactor($user)) {
            $this->redirect($stepUp->ask($stepUp->addFactorLevel($user), $this->passkeyReturnTo()));

            return null;
        }

        if ($user->passkeys()->count() >= PasskeyService::MAX_PER_USER) {
            $this->addError('passkey', __('me.security.passkey_limit', ['count' => PasskeyService::MAX_PER_USER]));

            return null;
        }

        return $passkeys->registrationOptions($user);
    }

    public function passkeyRegister(PasskeyService $passkeys, StepUpService $stepUp, string $response = ''): void
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $stepUp->mayAddFactor($user)) {
            $this->redirect($stepUp->ask($stepUp->addFactorLevel($user), $this->passkeyReturnTo()));

            return;
        }

        $passkey = strlen($response) <= 16_384 ? $passkeys->register($user, $response, request()->userAgent()) : null;

        if ($passkey === null) {
            $this->addError('passkey', __('me.security.passkey_failed'));

            return;
        }

        $stepUp->markFresh($user, 'passkey');
        $this->passkeyRegistered($passkey, $user);
    }
}

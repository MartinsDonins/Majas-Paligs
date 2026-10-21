<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Auth\StepUpService;
use App\Auth\TrustedDevices;
use App\Livewire\Concerns\AssertsPasskey;
use App\Livewire\Concerns\RegistersPasskey;
use App\Livewire\Me\Security;
use App\Models\Passkey;
use App\Models\User;
use App\Support\SafeReturn;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step-up confirmation (2 h idle, sliding while working, 12 h at most) before approvals, resets, exports and the
 * admin panels (ADR 0005, simplified by ADR 0006):
 * - a passkey tap ("Apstiprināt ar pirkstu / Face ID") when the account has one — MFA, also enough for dangerous
 *   actions;
 * - otherwise / as a fallback the authenticator app code (TOTP);
 * - a 6-digit e-mail code for section elders (never once the account has TOTP).
 * After a real MFA the person may tick "Uzticēties šai ierīcei 30 dienas" (TrustedDevices). An administrator who has
 * neither a passkey nor an authenticator creates a passkey right here (after an e-mail code if the login is older
 * than 15 minutes); one who confirmed with a code but has no passkey yet is offered one before continuing.
 */
#[Layout('layouts::guest')]
final class StepUp extends Component
{
    use AssertsPasskey;
    use RegistersPasskey;

    public string $code = '';

    public bool $sent = false;

    public bool $trust = false;

    /** Confirmed with a code, no passkey yet: offer one before continuing. */
    public bool $offer = false;

    public function mount(StepUpService $stepUp): void
    {
        if ($stepUp->hasFreshLevel($this->user(), $this->level())) {
            $this->redirect($this->intended());
        }
    }

    public function sendCode(StepUpService $stepUp): void
    {
        $user = $this->user();
        $key = 'step-up-send:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('code', __('auth.email.cooldown', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]));

            return;
        }
        RateLimiter::hit($key, 15 * 60);

        $this->sent = $stepUp->sendEmailCode($user);
    }

    public function verify(StepUpService $stepUp, TrustedDevices $devices): void
    {
        $user = $this->user();
        $key = 'step-up-verify:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->code = '';
            $this->addError('code', __('auth.email.cooldown', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]));

            return;
        }
        RateLimiter::hit($key, 15 * 60);

        $code = $this->code;
        $this->code = '';

        $ok = $user->hasTotp() ? $stepUp->verifyTotp($user, $code) : $stepUp->verifyEmailCode($user, $code);

        if (! $ok) {
            $this->addError('code', $user->hasTotp() && $stepUp->totpWasReplayed($user, $code)
                ? __('auth.step_up.replayed')
                : __('auth.step_up.invalid'));

            return;
        }

        RateLimiter::clear($key);

        if ($user->hasTotp() && $this->trust) {
            $devices->trust($user, request(), 'totp');
        }

        // An e-mail code is not enough for an administrator: stay here and create the passkey / authenticator.
        if (! $stepUp->hasFreshLevel($user, $this->level())) {
            $this->sent = false;

            return;
        }

        if ($this->level() !== 'any' && ! $user->hasPasskeys()) {
            $this->offer = true;

            return;
        }

        $this->redirect($this->intended());
    }

    /** "Vēlāk" on the passkey offer. */
    public function later(): void
    {
        $this->redirect($this->intended());
    }

    protected function passkeyUser(): User
    {
        return $this->user();
    }

    protected function passkeyVerified(Passkey $passkey, User $owner): void
    {
        app(StepUpService::class)->markFresh($owner, 'passkey');

        if ($this->trust) {
            app(TrustedDevices::class)->trust($owner, request(), 'passkey');
        }

        $this->redirect($this->intended());
    }

    protected function passkeyReturnTo(): string
    {
        return (string) (SafeReturn::path(session(StepUpService::INTENDED_KEY)) ?? route('home.redirect', absolute: false));
    }

    protected function passkeyRegistered(Passkey $passkey, User $user): void
    {
        if ($this->trust && ! $this->offer) { // after a code the device was already trusted in verify()
            app(TrustedDevices::class)->trust($user, request(), 'passkey');
        }

        session()->flash('status', 'passkey_added');
        $this->redirect($this->intended());
    }

    private function level(): string
    {
        $level = (string) session(StepUpService::LEVEL_KEY, 'any');

        return in_array($level, ['any', 'mfa', 'strong'], true) ? $level : 'any';
    }

    private function intended(): string
    {
        $target = session()->pull(StepUpService::INTENDED_KEY);
        session()->forget(StepUpService::LEVEL_KEY);

        // Same-origin pages only; absolute URLs of this app are accepted too (route() call sites used to store
        // https://… and were silently sent Home instead of back to /me/security or the heating report).
        return SafeReturn::path($target) ?? route('home.redirect');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(StepUpService $stepUp): View
    {
        $user = $this->user();

        $intended = (string) session(StepUpService::INTENDED_KEY, '');
        $level = $this->level();
        $needsMfa = $level !== 'any';
        $hasFactor = $user->hasMfaFactor();

        // The "authenticator app" alternative sends the person to /me/security; come back here afterwards.
        if ($needsMfa && ! $hasFactor && SafeReturn::path($intended) !== null) {
            session()->put(Security::AFTER_ENROL, $intended);
        }

        return view('livewire.auth.step-up', [
            'hasTotp' => $user->hasTotp(),
            'hasPasskeys' => $user->hasPasskeys(),
            'hasEmail' => $user->email !== null,
            'needsMfa' => $needsMfa,
            'strong' => $level === 'strong',
            // An administrator without any second factor: create a passkey here (now, or after an e-mail code).
            'mustEnrol' => $needsMfa && ! $hasFactor,
            'mayEnrol' => $needsMfa && ! $hasFactor && $stepUp->mayAddFactor($user),
            // Which wording the "set up" banner uses: platform panel vs. house administration.
            'forPlatform' => str_starts_with($intended, '/platform') || ($user->isPlatformAdmin() && ! str_starts_with($intended, '/manage')),
        ])->title(__('auth.step_up.title'));
    }
}

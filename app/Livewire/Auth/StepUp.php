<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Auth\StepUpService;
use App\Livewire\Me\Security;
use App\Models\User;
use App\Support\SafeReturn;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step-up confirmation (2 h idle, sliding while working, 12 h at most) before approvals, resets, exports and the
 * admin panels.
 * TOTP when configured (required for platform), otherwise a 6-digit e-mail code.
 */
#[Layout('layouts::guest')]
final class StepUp extends Component
{
    public string $code = '';

    public bool $sent = false;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $stepUp = app(StepUpService::class);

        $needsMfa = session(StepUpService::LEVEL_KEY) === 'mfa';
        if ($needsMfa ? $stepUp->hasFreshMfa($user) : $stepUp->hasFresh($user)) {
            $this->redirect($this->intended());
        }
    }

    public function sendCode(StepUpService $stepUp): void
    {
        /** @var User $user */
        $user = auth()->user();
        $key = 'step-up-send:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('code', __('auth.email.cooldown', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]));

            return;
        }
        RateLimiter::hit($key, 15 * 60);

        $this->sent = $stepUp->sendEmailCode($user);
    }

    public function verify(StepUpService $stepUp): void
    {
        /** @var User $user */
        $user = auth()->user();
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
        $this->redirect($this->intended());
    }

    private function intended(): string
    {
        $target = session()->pull(StepUpService::INTENDED_KEY);
        session()->forget(StepUpService::LEVEL_KEY);

        // Same-origin pages only; absolute URLs of this app are accepted too (route() call sites used to store
        // https://… and were silently sent Home instead of back to /me/security or the heating report).
        return SafeReturn::path($target) ?? route('home.redirect');
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        $intended = (string) session(StepUpService::INTENDED_KEY, '');
        $needsMfa = session(StepUpService::LEVEL_KEY) === 'mfa';

        // The "set up an authenticator" banner sends the person to /me/security; come back here afterwards.
        if ($needsMfa && ! $user->hasTotp() && SafeReturn::path($intended) !== null) {
            session()->put(Security::AFTER_ENROL, $intended);
        }

        return view('livewire.auth.step-up', [
            'hasTotp' => $user->hasTotp(),
            'hasEmail' => $user->email !== null,
            'needsMfa' => $needsMfa,
            // Which wording the "set up an authenticator" banner uses: platform panel vs. house administration.
            'forPlatform' => str_starts_with($intended, '/platform') || ($user->isPlatformAdmin() && ! str_starts_with($intended, '/manage')),
        ])->title(__('auth.step_up.title'));
    }
}

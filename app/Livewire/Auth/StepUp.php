<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Auth\StepUpService;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step-up confirmation (valid 2 h) before approvals, resets, exports and the admin panels.
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

        $needsMfa = session('mp.step_up.level') === 'mfa';
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
            $this->addError('code', __('auth.email.cooldown'));

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
            $this->addError('code', __('auth.email.cooldown'));

            return;
        }
        RateLimiter::hit($key, 15 * 60);

        $code = $this->code;
        $this->code = '';

        $ok = $user->hasTotp() ? $stepUp->verifyTotp($user, $code) : $stepUp->verifyEmailCode($user, $code);

        if (! $ok) {
            $this->addError('code', __('auth.step_up.invalid'));

            return;
        }

        RateLimiter::clear($key);
        $this->redirect($this->intended());
    }

    private function intended(): string
    {
        $target = (string) session()->pull('mp.step_up.intended', route('home.redirect'));
        session()->forget('mp.step_up.level');

        return str_starts_with($target, '/') && ! str_starts_with($target, '//') ? $target : route('home.redirect');
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('livewire.auth.step-up', [
            'hasTotp' => $user->hasTotp(),
            'hasEmail' => $user->email !== null,
            'needsMfa' => session('mp.step_up.level') === 'mfa',
        ])->title(__('auth.step_up.title'));
    }
}

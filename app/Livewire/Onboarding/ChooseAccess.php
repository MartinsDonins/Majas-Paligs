<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Auth\LoginHandleGenerator;
use App\Auth\MagicLinkService;
use App\Auth\PinAuthService;
use App\Auth\SessionEpoch;
use App\Auth\WeakPinRule;
use App\Models\User;
use App\Services\Onboarding\JoinCompleter;
use App\Services\Onboarding\JoinState;
use App\Support\Identifiers;
use App\Tenancy\Exceptions\HouseClosed;
use App\Tenancy\InviteResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step 4: access method — "Ar e-pastu" (magic link + code, membership created after the e-mail is confirmed in
 * this session) or "Bez e-pasta — ar PIN" (personal handle apt-XXXX + 6-digit PIN, account created now).
 * "Atcerēties šo telefonu" is on by default. Ends in the pending state.
 */
#[Layout('layouts::guest')]
final class ChooseAccess extends Component
{
    public string $method = '';

    public string $email = '';

    public string $pin = '';

    public string $pinConfirm = '';

    public bool $remember = true;

    public bool $mailSent = false;

    public ?string $createdHandle = null;

    public ?string $slug = null;

    public function mount(InviteResolver $resolver): void
    {
        if (auth()->check()) {
            $this->redirectRoute('join.role');

            return;
        }

        $state = JoinState::get(request());

        if ($state === null || $state->role === null) {
            $this->redirectRoute('join');
        }
    }

    public function pick(string $method): void
    {
        $this->method = in_array($method, ['email', 'pin'], true) ? $method : '';
        $this->resetErrorBag();
    }

    public function useEmail(MagicLinkService $magic): void
    {
        $state = JoinState::get(request());
        if ($state === null || $state->role === null) {
            $this->redirectRoute('join');

            return;
        }

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

        $user = User::query()->where('email', $email)->whereNull('anonymised_at')->first()
            ?? User::query()->create(['email' => $email, 'locale' => app()->getLocale()]);

        $magic->issueFor($user, request()->ip());

        // Same screen whether the address was new or known (no account oracle).
        $this->mailSent = true;
        session()->flash('login_email', $email);
    }

    public function usePin(PinAuthService $pins, LoginHandleGenerator $handles, JoinCompleter $completer, InviteResolver $resolver): void
    {
        $state = JoinState::get(request());
        if ($state === null || $state->role === null) {
            $this->redirectRoute('join');

            return;
        }

        try {
            $this->validate([
                'pin' => ['required', 'string', new WeakPinRule($state->invite->apartmentNumber)],
                'pinConfirm' => ['required', 'same:pin'],
            ], [
                'pin.required' => __('auth.pin.format'),
                'pinConfirm.required' => __('auth.pin.mismatch'),
                'pinConfirm.same' => __('auth.pin.mismatch'),
            ]);
        } catch (ValidationException $e) {
            // Never send a rejected PIN back in the component snapshot.
            $this->pin = '';
            $this->pinConfirm = '';

            throw $e;
        }

        try {
            if ($resolver->resume($state->invite) === null) {
                $this->redirectRoute('join');

                return;
            }
        } catch (HouseClosed) {
            $this->redirectRoute('join');

            return;
        }

        $user = DB::transaction(function () use ($pins, $handles, $state): User {
            $user = User::query()->create(['locale' => app()->getLocale()]);
            $user->forceFill(['login_handle' => $handles->generate($state->invite->apartmentNumber)])->save();
            $pins->setPin($user, $this->pin, $state->invite->apartmentNumber);

            return $user;
        });

        $this->pin = '';
        $this->pinConfirm = '';

        JoinState::forget(request());
        $slug = $completer->complete($user, $state);

        Auth::guard('web')->login($user, $this->remember);
        session()->regenerate();
        SessionEpoch::sync($user);
        $user->forceFill(['last_login_at' => now()])->save();

        $this->createdHandle = $user->login_handle;
        $this->slug = $slug;
    }

    public function render(): View
    {
        return view('livewire.onboarding.choose-access')->title(__('onboarding.access.title'));
    }
}

<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use App\Services\Onboarding\JoinCompleter;
use App\Services\Onboarding\JoinState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Finishes any login (link, code, PIN): session fixation protection, remember-me, last_login_at, completes a
 * pending /join started before the e-mail was confirmed, then redirects (PIN change first if required).
 */
final class LoginCompleter
{
    public function __construct(private readonly JoinCompleter $joins) {}

    public function login(Request $request, User $user, bool $remember, string $method): RedirectResponse
    {
        $join = JoinState::pull($request);

        Auth::guard('web')->login($user, $remember);
        session()->regenerate();
        SessionEpoch::sync($user);

        if ($user->email !== null && $user->email_verified_at === null && str_starts_with($method, 'email')) {
            $user->forceFill(['email_verified_at' => now()]);
        }
        $user->forceFill(['last_login_at' => now()])->save();

        if ($user->pin_must_change) {
            return redirect()->route('login.pin.new');
        }

        if ($join !== null && $join->role !== null) {
            $slug = $this->joins->complete($user, $join);

            if ($slug !== null) {
                return redirect()->route('building.home', ['building' => $slug])->with('status', 'joined');
            }
        }

        return redirect()->route('home.redirect');
    }
}

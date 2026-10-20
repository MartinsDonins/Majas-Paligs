<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\Locale;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Services\Onboarding\JoinCompleter;
use App\Services\Onboarding\JoinState;
use App\Support\SafeReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Finishes any login (link, code, PIN): session fixation protection, remember-me, last_login_at, the device
 * language, completes a pending /join started before the e-mail was confirmed, then redirects (PIN change first if
 * required; otherwise the deep link the visitor originally opened, e.g. /b/{slug}/water from a notice e-mail).
 */
final class LoginCompleter
{
    public function __construct(private readonly JoinCompleter $joins) {}

    public function login(Request $request, User $user, bool $remember, string $method): RedirectResponse
    {
        $join = JoinState::pull($request);
        $intended = SafeReturn::path($request->session()->pull('url.intended'));

        Auth::guard('web')->login($user, $remember);
        session()->regenerate();
        SessionEpoch::sync($user);

        // The language the visitor used on the login page wins (QA: RU chosen as a guest switched back to LV at the
        // moment of login, because SetLocale prefers users.locale over the device cookie).
        $deviceLocale = Locale::tryFrom((string) $request->cookie(SetLocale::COOKIE));
        if ($deviceLocale !== null && $deviceLocale !== $user->locale) {
            $user->forceFill(['locale' => $deviceLocale->value]);
            app()->setLocale($deviceLocale->value);
        }

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

        return $intended !== null ? redirect($intended) : redirect()->route('home.redirect');
    }
}

<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Per-user session epoch (review fix, drošība): every credential change bumps `users.auth_epoch` and rotates the
 * remember token, so every OTHER live session (Redis) and every "remember this phone" cookie of that user stops
 * working at its next request (EnsureSessionEpoch). The acting user's own session is re-synced and stays logged in.
 *
 * Call sites: PIN set / reset (PinAuthService), elder temp code, TOTP enable / disable, role revoke, "Aizmirst
 * ierīces", account erasure.
 */
final class SessionEpoch
{
    public const SESSION_KEY = 'mp.epoch';

    public static function bump(User $user): void
    {
        $user->forceFill([
            'auth_epoch' => (int) $user->auth_epoch + 1,
            'remember_token' => Str::random(60),
        ])->save();

        if (auth()->id() !== null && (int) auth()->id() === (int) $user->id && app()->bound('session')) {
            self::sync($user);
        }
    }

    /**
     * Store the user's current epoch in this session (after login, or after the user changed their own credential).
     */
    public static function sync(User $user): void
    {
        session()->put(self::SESSION_KEY, (int) $user->auth_epoch);
    }
}

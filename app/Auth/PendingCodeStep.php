<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;

/**
 * "A code is on its way" state of the e-mail login (/login) and the e-mail step-up (/step-up), kept in the SESSION
 * instead of only in the Livewire component (FIX-007.3). The LV/RU toggle is a plain POST + full reload: with the
 * state in the component alone, the reloaded page forgot that a code had been sent and showed the e-mail form
 * again, so the person had to request (and wait for) a new code.
 *
 * Nothing secret is stored: the address the visitor typed, the "remember" choice and when the mail was requested.
 * The state lapses with the code itself (magic_link.ttl_minutes / step_up.code_ttl_minutes) and is cleared by
 * "Cita adrese", by every completed login and by a confirmed step-up.
 */
final class PendingCodeStep
{
    public const LOGIN_KEY = 'login.pending_code';

    public const STEP_UP_KEY = 'step_up.pending_code';

    public static function rememberLogin(string $email, bool $remember): void
    {
        session()->put(self::LOGIN_KEY, ['email' => $email, 'remember' => $remember, 'at' => now()->getTimestamp()]);
    }

    /**
     * The pending e-mail login of this session, if its code can still be valid.
     *
     * @return array{email: string, remember: bool}|null
     */
    public static function login(): ?array
    {
        $pending = session(self::LOGIN_KEY);
        if (! is_array($pending) || ! is_string($pending['email'] ?? null) || $pending['email'] === ''
            || ! self::fresh($pending['at'] ?? null, (int) config('majas.magic_link.ttl_minutes', 15))) {
            return null;
        }

        return ['email' => $pending['email'], 'remember' => (bool) ($pending['remember'] ?? true)];
    }

    public static function forgetLogin(): void
    {
        session()->forget(self::LOGIN_KEY);
    }

    public static function rememberStepUp(User $user): void
    {
        session()->put(self::STEP_UP_KEY, ['user' => (int) $user->id, 'at' => now()->getTimestamp()]);
    }

    /** True when an e-mail step-up code was sent to THIS user in this session and can still be valid. */
    public static function stepUp(User $user): bool
    {
        $pending = session(self::STEP_UP_KEY);

        return is_array($pending) && (int) ($pending['user'] ?? 0) === (int) $user->id
            && self::fresh($pending['at'] ?? null, (int) config('majas.step_up.code_ttl_minutes', 10));
    }

    public static function forgetStepUp(): void
    {
        session()->forget(self::STEP_UP_KEY);
    }

    private static function fresh(mixed $at, int $minutes): bool
    {
        return is_int($at) && $at <= now()->getTimestamp() && $at > now()->subMinutes($minutes)->getTimestamp();
    }
}

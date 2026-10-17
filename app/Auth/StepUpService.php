<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\LoginTokenPurpose;
use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\StepUpCode;
use App\Support\Identifiers;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

/**
 * Step-up confirmation for elevated actions (PLANNING §4.5 item 4): approvals, reveals, exports, panels.
 * Valid 2 h. Methods: TOTP (MFA, required for platform_admin) or a 6-digit e-mail code (manage panel /
 * elder actions). Elevated roles therefore need an e-mail.
 */
class StepUpService
{
    public const SESSION_KEY = 'mp.step_up';

    public function __construct(private readonly Google2FA $google2fa) {}

    public function hasFresh(User $user): bool
    {
        $state = session(self::SESSION_KEY);

        if (! is_array($state) || (int) ($state['user'] ?? 0) !== (int) $user->id) {
            return false;
        }

        $minutes = (int) config('majas.step_up.valid_minutes', 120);

        return (int) ($state['at'] ?? 0) >= now()->subMinutes($minutes)->getTimestamp();
    }

    /**
     * Fresh step-up performed with a second factor (TOTP).
     */
    public function hasFreshMfa(User $user): bool
    {
        return $this->hasFresh($user) && (session(self::SESSION_KEY)['method'] ?? null) === 'totp';
    }

    public function markFresh(User $user, string $method): void
    {
        session()->put(self::SESSION_KEY, ['user' => (int) $user->id, 'at' => now()->getTimestamp(), 'method' => $method]);
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Send a 6-digit step-up code to the user's e-mail.
     */
    public function sendEmailCode(User $user): bool
    {
        if ($user->email === null || $user->isTombstone()) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        LoginToken::query()
            ->where('user_id', $user->id)
            ->where('purpose', LoginTokenPurpose::StepUp->value)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        LoginToken::query()->create([
            'user_id' => $user->id,
            'email_hmac' => Identifiers::emailHmac($user->email),
            'code_hash' => MagicLinkService::codeHash($code),
            'purpose' => LoginTokenPurpose::StepUp->value,
            'expires_at' => now()->addMinutes((int) config('majas.step_up.code_ttl_minutes', 10)),
        ]);

        $user->notify(new StepUpCode($code));

        return true;
    }

    public function verifyEmailCode(User $user, #[\SensitiveParameter] string $code): bool
    {
        $token = LoginToken::query()
            ->where('user_id', $user->id)
            ->where('purpose', LoginTokenPurpose::StepUp->value)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($token === null) {
            return false;
        }

        $token->increment('attempts');

        if ($token->attempts > (int) config('majas.magic_link.code_attempts', 5)) {
            $token->forceFill(['used_at' => now()])->save();

            return false;
        }

        if (! hash_equals((string) $token->code_hash, MagicLinkService::codeHash(preg_replace('/\D/', '', $code) ?? ''))) {
            return false;
        }

        $token->forceFill(['used_at' => now()])->save();
        $this->markFresh($user, 'email');

        return true;
    }

    public function verifyTotp(User $user, #[\SensitiveParameter] string $code): bool
    {
        if (! $user->hasTotp()) {
            return false;
        }

        $code = (string) preg_replace('/\D/', '', $code);
        $timestamp = $this->google2fa->verifyKeyNewer(
            (string) $user->app_authentication_secret,
            $code,
            (int) Cache::get($this->replayKey($user), 0),
            1,
        );

        if ($timestamp === false) {
            return false;
        }

        // Replay protection: the same (or an older) time step can never be used twice.
        Cache::put($this->replayKey($user), $timestamp === true ? $this->google2fa->getTimestamp() : $timestamp, now()->addMinutes(10));
        $this->markFresh($user, 'totp');

        return true;
    }

    private function replayKey(User $user): string
    {
        return 'mp:totp-ts:'.$user->id;
    }
}

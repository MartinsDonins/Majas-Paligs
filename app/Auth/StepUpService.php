<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\BuildingRoleType;
use App\Enums\LoginTokenPurpose;
use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\StepUpCode;
use App\Support\Identifiers;
use App\Tenancy\Data\RoleData;
use App\Tenancy\UserScopedQuery;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

/**
 * Step-up confirmation for elevated actions (PLANNING §4.5 item 4): approvals, reveals, exports, panels.
 * Valid 2 h. Methods: TOTP (MFA — required for platform_admin, building_admin and parking_officer, and the only
 * method accepted once an account has an authenticator) or a 6-digit e-mail code (section elders; also the
 * confirmation before a FIRST authenticator enrolment). Elevated roles therefore need an e-mail.
 */
class StepUpService
{
    public const SESSION_KEY = 'mp.step_up';

    /**
     * Where to return after the step-up, and which level it needs. Deliberately NOT nested under SESSION_KEY:
     * markFresh() replaces that whole array, which used to wipe the return URL (found by the real-browser
     * Browser/OnboardingSmokeTest — the elder landed on Home instead of the help panel).
     */
    public const INTENDED_KEY = 'mp.step_up_intended';

    public const LEVEL_KEY = 'mp.step_up_level';

    public function __construct(private readonly Google2FA $google2fa) {}

    public function hasFresh(User $user): bool
    {
        $state = session(self::SESSION_KEY);

        if (! is_array($state) || (int) ($state['user'] ?? 0) !== (int) $user->id) {
            return false;
        }

        $minutes = (int) config('majas.step_up.valid_minutes', 120);

        if ((int) ($state['at'] ?? 0) < now()->subMinutes($minutes)->getTimestamp()) {
            return false;
        }

        // An account with an authenticator only counts a TOTP step-up (an e-mail step-up taken before the
        // authenticator was enrolled no longer counts either).
        return ! $user->hasTotp() || ($state['method'] ?? null) === 'totp';
    }

    /**
     * Fresh step-up performed with a second factor (TOTP).
     */
    public function hasFreshMfa(User $user): bool
    {
        return $this->hasFresh($user) && (session(self::SESSION_KEY)['method'] ?? null) === 'totp';
    }

    /**
     * Roles whose step-up must be a second factor (review fix, PLANNING §5 A07 "MFA for roles"): platform_admin,
     * and building_admin / parking_officer in any building — they reach reveals, the police export chain, invite
     * letter printing and membership approvals. A section elder keeps the e-mail code.
     */
    public function requiresMfa(User $user): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return UserScopedQuery::for($user)->roles()->contains(
            static fn (RoleData $r): bool => in_array($r->role, [BuildingRoleType::BuildingAdmin, BuildingRoleType::ParkingOfficer], true),
        );
    }

    /**
     * The step-up this user needs for elevated actions: TOTP for MFA roles, otherwise any fresh step-up.
     */
    public function hasFreshFor(User $user): bool
    {
        return $this->requiresMfa($user) ? $this->hasFreshMfa($user) : $this->hasFresh($user);
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
        // Never an e-mail step-up for an account with an authenticator; shared cumulative wrong-code budget.
        if ($user->hasTotp() || $user->email === null || MagicLinkService::codeLoginLocked(Identifiers::emailHmac($user->email))) {
            return false;
        }

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
            MagicLinkService::registerCodeFailure(Identifiers::emailHmac($user->email));

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

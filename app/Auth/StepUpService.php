<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\BuildingRoleType;
use App\Enums\LoginTokenPurpose;
use App\Mcp\McpAccess;
use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\StepUpCode;
use App\Support\Identifiers;
use App\Support\SafeReturn;
use App\Tenancy\Data\RoleData;
use App\Tenancy\UserScopedQuery;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use PragmaRX\Google2FA\Google2FA;

/**
 * Step-up confirmation for elevated actions (PLANNING §4.5 item 4): approvals, reveals, exports, panels.
 * Valid while the person keeps working: it lapses after `valid_minutes` (120) WITHOUT a step-up-protected request
 * (RequireStepUp touches it) and at the latest `max_minutes` (720) after the confirmation.
 *
 * Methods (ADR 0006):
 * - `passkey` — a passkey login or tap (user verification required): MFA, phishing-resistant.
 * - `totp` — authenticator app code: MFA.
 * - `trusted` — this browser was marked "Uzticēties šai ierīcei 30 dienas" right after a real MFA (TrustedDevices):
 *   counts as MFA for the normal step-up, NEVER for a dangerous action (hasFreshStrongMfa).
 * - `email` — 6-digit e-mail code, or an e-mail login in this session (same factor, same proof). Section elders;
 *   also the first-factor proof before a FIRST second factor is added. Never counts once the account has TOTP.
 *
 * Levels: any (hasFresh) < mfa (hasFreshMfa: passkey / totp / trusted; required for platform_admin,
 * building_admin, parking_officer) < strong (hasFreshStrongMfa: passkey / totp in THIS session — break-glass, granting
 * a role to someone else, adding or removing a sign-in factor).
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

    /** Methods that are a second factor. */
    public const MFA_METHODS = ['passkey', 'totp', 'trusted'];

    /** Methods proven in THIS session (a trusted device does not count). */
    public const STRONG_METHODS = ['passkey', 'totp'];

    /** Last login of this session: ['user' => id, 'at' => timestamp, 'method' => …] (LoginCompleter). */
    public const LOGIN_KEY = 'mp.login';

    /** How recent a login must be to count as the first-factor proof before a FIRST second factor is added. */
    private const RECENT_LOGIN_MINUTES = 15;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly TrustedDevices $trustedDevices,
    ) {}

    public function hasFresh(User $user): bool
    {
        $state = $this->state($user);

        // An account with an authenticator only counts a second factor (an e-mail step-up taken before the
        // authenticator was enrolled no longer counts either).
        if ($state !== null && (! $user->hasTotp() || in_array($state['method'], self::MFA_METHODS, true))) {
            return true;
        }

        return $this->adoptTrustedDevice($user);
    }

    /**
     * Fresh step-up performed with a second factor (passkey, TOTP) or on a trusted device.
     */
    public function hasFreshMfa(User $user): bool
    {
        if ($this->mcpWriteElevated($user)) {
            return true;
        }

        $state = $this->state($user);

        if ($state !== null && in_array($state['method'], self::MFA_METHODS, true)) {
            return true;
        }

        return $this->adoptTrustedDevice($user);
    }

    /**
     * Fresh second factor proven in THIS session (passkey or TOTP) — for dangerous actions. A trusted device never
     * satisfies it, but one passkey tap does.
     */
    public function hasFreshStrongMfa(User $user): bool
    {
        if ($this->mcpWriteElevated($user)) {
            return true;
        }

        $state = $this->state($user);

        return $state !== null && in_array($state['method'], self::STRONG_METHODS, true);
    }

    /**
     * Fresh check for a RequireStepUp / StepUp level: 'any', 'mfa' or 'strong'.
     */
    public function hasFreshLevel(User $user, string $level): bool
    {
        return match ($level) {
            'strong' => $this->hasFreshStrongMfa($user),
            'mfa' => $this->hasFreshMfa($user),
            default => $this->hasFresh($user),
        };
    }

    /**
     * Who may ADD a sign-in factor (passkey or authenticator app): an account that already has one needs a fresh
     * second factor in this session (otherwise a mailbox or session thief could add their own and bypass it); an
     * account without one needs a fresh first-factor proof — an e-mail step-up, or a login in the last 15 minutes.
     */
    public function mayAddFactor(User $user): bool
    {
        if ($user->hasMfaFactor()) {
            return $this->hasFreshStrongMfa($user);
        }

        if ($this->hasFresh($user)) {
            return true;
        }

        $login = session(self::LOGIN_KEY);

        return is_array($login) && (int) ($login['user'] ?? 0) === (int) $user->id
            && (int) ($login['at'] ?? 0) >= now()->subMinutes(self::RECENT_LOGIN_MINUTES)->getTimestamp();
    }

    /**
     * The level to ask for before adding a factor (see mayAddFactor).
     */
    public function addFactorLevel(User $user): string
    {
        return $user->hasMfaFactor() ? 'strong' : 'any';
    }

    /**
     * Remember where to come back to and which level is needed; returns the step-up URL to redirect to.
     */
    public function ask(string $level, ?string $returnTo): string
    {
        session()->put(self::INTENDED_KEY, SafeReturn::path($returnTo) ?? route('home.redirect', absolute: false));
        session()->put(self::LEVEL_KEY, in_array($level, ['any', 'mfa', 'strong'], true) ? $level : 'mfa');

        return route('step-up', absolute: false);
    }

    /**
     * Before a dangerous action in a Livewire / Filament component: true when a passkey / TOTP of THIS session is
     * fresh; otherwise sends the person to the step-up (one passkey tap or code) and back to the page they are on
     * (the session's previous URL — the page GET, never the /livewire/update endpoint).
     */
    public function demandStrong(User $user, Component $livewire): bool
    {
        if ($this->hasFreshStrongMfa($user)) {
            return true;
        }

        $livewire->redirect($this->ask('strong', session()->previousUrl()));

        return false;
    }

    /**
     * The fresh step-up state of this session for $user (method + times), or null.
     *
     * @return array{user: int, at: int, seen: int, method: string}|null
     */
    private function state(User $user): ?array
    {
        $state = session(self::SESSION_KEY);

        if (! is_array($state) || (int) ($state['user'] ?? 0) !== (int) $user->id) {
            return null;
        }

        $at = (int) ($state['at'] ?? 0);
        $seen = max($at, (int) ($state['seen'] ?? 0));
        $idle = (int) config('majas.step_up.valid_minutes', 120);
        $max = max($idle, (int) config('majas.step_up.max_minutes', 720));

        if ($seen < now()->subMinutes($idle)->getTimestamp() || $at < now()->subMinutes($max)->getTimestamp()) {
            return null;
        }

        return ['user' => (int) $state['user'], 'at' => $at, 'seen' => $seen, 'method' => (string) ($state['method'] ?? '')];
    }

    /**
     * A genuine trusted-device cookie of this account (same session epoch, not expired) starts a 'trusted' step-up
     * for this session — audited once per session, not per request.
     */
    private function adoptTrustedDevice(User $user): bool
    {
        if (! app()->bound('request') || ! app()->bound('session')) {
            return false;
        }

        $device = $this->trustedDevices->current($user, request());

        if ($device === null) {
            return false;
        }

        $this->trustedDevices->markUsed($device);
        $this->markFresh($user, 'trusted');

        return true;
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

    /**
     * MCP (round 3, ADR 0008): inside a CONFIRMED write tool of a `write` token of this very user (McpAccess::elevated),
     * the token stands in for the session step-up — the token itself was created behind a strong step-up, expires within
     * 30 days, is bound to the user's auth epoch, and every call is audited. Never true for a read token, outside a write
     * tool, or for anyone else.
     */
    private function mcpWriteElevated(User $user): bool
    {
        return app()->bound(McpAccess::class) && app(McpAccess::class)->authorisesWrite($user);
    }

    public function markFresh(User $user, string $method): void
    {
        $now = now()->getTimestamp();
        session()->put(self::SESSION_KEY, ['user' => (int) $user->id, 'at' => $now, 'seen' => $now, 'method' => $method]);
    }

    /**
     * Activity on a step-up-protected page keeps a still-fresh step-up alive (sliding idle window). Never revives
     * an expired one and never extends past max_minutes after the confirmation.
     */
    public function touch(User $user): void
    {
        if ($this->state($user) === null) {
            return;
        }

        $state = (array) session(self::SESSION_KEY);
        $state['seen'] = now()->getTimestamp();
        session()->put(self::SESSION_KEY, $state);
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

        $user->notify((new StepUpCode($code))->locale(app()->getLocale()));

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

    /**
     * True when the code is right but its 30-second window was already used (replay protection) — the person only
     * has to wait for the next code. Used for a clearer message than "Kods neder".
     */
    public function totpWasReplayed(User $user, #[\SensitiveParameter] string $code): bool
    {
        if (! $user->hasTotp()) {
            return false;
        }

        $code = (string) preg_replace('/\D/', '', $code);

        return $code !== '' && $this->google2fa->verifyKey((string) $user->app_authentication_secret, $code, 1) === true;
    }

    /**
     * Enrolment just proved possession of the new authenticator with a valid code: count it as the TOTP step-up
     * (one prompt per login instead of a second code right after enrolling) and burn that time step for replays.
     */
    public function markEnrolled(User $user, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code): void
    {
        $timestamp = $this->google2fa->verifyKeyNewer($secret, (string) preg_replace('/\D/', '', $code), (int) Cache::get($this->replayKey($user), 0), 1);
        Cache::put($this->replayKey($user), is_int($timestamp) ? $timestamp : $this->google2fa->getTimestamp(), now()->addMinutes(10));
        $this->markFresh($user, 'totp');
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

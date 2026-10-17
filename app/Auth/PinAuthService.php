<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditLogger;
use App\Models\Building;
use App\Models\User;
use App\Notifications\Alerts;
use App\Services\Invites\InviteCodeService;
use App\Support\Identifiers;
use App\Tenancy\UserScopedQuery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * PIN login for residents without e-mail (PLANNING §4.5, MVP-SPEC A.5/A.6, AUTH-003).
 *
 * Blocking limiters (all must pass), in order: IP (20/h, IPv6 by /64) → handle (5 wrong → 15-min cooldown,
 * 10/day → locked until an elder reset) → building (100/h; alerts at 50% and on hit, then PIN login for that
 * building pauses 15 min). The global `pin-global` monitor is ALERT-ONLY: it never refuses a login.
 * Unknown handles count against the IP limiter and the global monitor only and run a dummy Argon2id verify.
 */
class PinAuthService
{
    public function __construct(
        private readonly PinHasher $hasher,
        private readonly Alerts $alerts,
    ) {}

    public static function normalizeHandle(string $handle): string
    {
        $h = mb_strtoupper(trim($handle), 'UTF-8');
        $h = strtr($h, InviteCodeService::CYRILLIC_MAP);
        $h = (string) preg_replace('/[\s_.]+/u', '', $h);

        // "145K7MQ" → "145-K7MQ"
        if (preg_match('/^(\d{1,4})-?([A-Z0-9]{4})$/', $h, $m) === 1) {
            return $m[1].'-'.$m[2];
        }

        return $h;
    }

    public function attempt(string $handle, #[\SensitiveParameter] string $pin, string $ip): PinLoginResult
    {
        $cfg = config('majas.pin');
        $ipKey = 'pin-ip:'.Identifiers::ipBucket($ip);

        // 1. IP limiter (drives the calm cooldown screen).
        if (RateLimiter::tooManyAttempts($ipKey, (int) $cfg['ip_per_hour'])) {
            $this->hasher->dummyVerify($pin);

            return PinLoginResult::cooldown();
        }

        $user = User::query()
            ->where('login_handle', self::normalizeHandle($handle))
            ->whereNull('anonymised_at')
            ->first();

        // Unknown handle: IP limiter + global monitor only.
        if ($user === null) {
            $this->hasher->dummyVerify($pin);
            RateLimiter::hit($ipKey, 3600);
            $this->monitorGlobal(unknown: true);

            return PinLoginResult::failed();
        }

        $handleKey = 'pin-handle:'.$user->id;
        $dayKey = 'pin-handle-day:'.$user->id;
        $buildingIds = UserScopedQuery::for($user)->buildingIds();

        // 2. Handle limiter (cooldown / daily lock) — same generic failure.
        $locked = ($user->pin_locked_until !== null && $user->pin_locked_until->isFuture())
            || RateLimiter::tooManyAttempts($handleKey, (int) $cfg['handle_cooldown_after']);

        // 3. Building limiter / pause.
        $paused = $this->anyBuildingPaused($buildingIds);

        if ($locked || $paused || $user->pin_hash === null && $user->pin_temp_code_hash === null) {
            $this->hasher->dummyVerify($pin);
            $this->registerFailure($user, $ipKey, $handleKey, $dayKey, $buildingIds);

            return PinLoginResult::failed();
        }

        // 4. Verify PIN, then the temporary reset code (single use, 24 h, forces a new PIN).
        if ($user->pin_hash !== null && $this->hasher->verify($pin, $user->pin_hash)) {
            RateLimiter::clear($handleKey);
            $user->forceFill(['pin_failed_count' => 0])->save();

            return PinLoginResult::success($user, (bool) $user->pin_must_change);
        }

        if ($user->pin_temp_code_hash !== null
            && $user->pin_temp_code_expires_at !== null
            && $user->pin_temp_code_expires_at->isFuture()
            && $this->hasher->verify(self::normalizeTempCode($pin), $user->pin_temp_code_hash)) {
            RateLimiter::clear($handleKey);
            $user->forceFill([
                'pin_temp_code_hash' => null,
                'pin_temp_code_expires_at' => null,
                'pin_must_change' => true,
                'pin_failed_count' => 0,
                'pin_locked_until' => null,
            ])->save();

            return PinLoginResult::success($user, true);
        }

        $this->registerFailure($user, $ipKey, $handleKey, $dayKey, $buildingIds);

        return PinLoginResult::failed();
    }

    /**
     * Set a new PIN (creation, reset, change) — enforces WeakPinRule.
     *
     * @throws ValidationException
     */
    public function setPin(User $user, #[\SensitiveParameter] string $pin, ?int $apartmentNumber = null): void
    {
        $rule = new WeakPinRule($apartmentNumber);

        if (preg_match('/^\d{6}$/', $pin) !== 1) {
            throw ValidationException::withMessages(['pin' => __('auth.pin.format')]);
        }

        if ($rule->weakReason($pin) !== null) {
            throw ValidationException::withMessages(['pin' => __('auth.pin.weak')]);
        }

        $user->forceFill([
            'pin_hash' => $this->hasher->hash($pin),
            'pin_must_change' => false,
            'pin_failed_count' => 0,
            'pin_locked_until' => null,
            'pin_temp_code_hash' => null,
            'pin_temp_code_expires_at' => null,
        ])->save();
    }

    /**
     * Elder reset: one-time temporary code (8 chars, unambiguous alphabet, Argon2id, 24 h, single use).
     * Caller (Elder\PinReset) authorises (own section, step-up, 5/day cap), audits and notifies.
     */
    public function issueTempCode(User $user): string
    {
        $code = InviteCodeService::randomString(
            (string) config('majas.pin.handle_alphabet'),
            (int) config('majas.pin.temp_code_length', 8),
        );

        $user->forceFill([
            'pin_temp_code_hash' => $this->hasher->hash($code),
            'pin_temp_code_expires_at' => now()->addHours((int) config('majas.pin.temp_code_hours', 24)),
            'pin_locked_until' => null,
            'pin_failed_count' => 0,
        ])->save();

        RateLimiter::clear('pin-handle:'.$user->id);
        RateLimiter::clear('pin-handle-day:'.$user->id);

        return $code;
    }

    public static function normalizeTempCode(string $code): string
    {
        $c = mb_strtoupper(trim($code), 'UTF-8');

        return (string) preg_replace('/[\s\-]+/u', '', strtr($c, InviteCodeService::CYRILLIC_MAP));
    }

    /**
     * Manual or automatic PIN pause state of a building (settings.pin_paused_until or the 15-min auto pause).
     */
    public function buildingPaused(Building $building): bool
    {
        return $building->settings->pinPaused() || Cache::has('pin-building-paused:'.$building->id);
    }

    /**
     * @param  list<int>  $buildingIds
     */
    private function anyBuildingPaused(array $buildingIds): bool
    {
        if ($buildingIds === []) {
            return false;
        }

        foreach (Building::query()->whereIn('id', $buildingIds)->get() as $building) {
            if ($this->buildingPaused($building)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $buildingIds
     */
    private function registerFailure(User $user, string $ipKey, string $handleKey, string $dayKey, array $buildingIds): void
    {
        $cfg = config('majas.pin');

        RateLimiter::hit($ipKey, 3600);
        RateLimiter::hit($handleKey, (int) $cfg['handle_cooldown_minutes'] * 60);
        RateLimiter::hit($dayKey, 86400);

        $user->forceFill(['pin_failed_count' => $user->pin_failed_count + 1])->save();

        if (RateLimiter::attempts($dayKey) >= (int) $cfg['handle_daily_lock_after'] && $user->pin_locked_until === null) {
            // Locked until an elder reset (issueTempCode clears it).
            $user->forceFill(['pin_locked_until' => now()->addYears(10)])->save();
            AuditLogger::record('pin.locked', $user, [(int) $user->id], platform: true, actorRole: 'system', actorUserId: null);
        }

        foreach (Building::query()->whereIn('id', $buildingIds)->get() as $building) {
            $limit = (int) $building->settings->pinLimits['building_hour'];
            $key = 'pin-building:'.$building->id;
            RateLimiter::hit($key, 3600);
            $count = RateLimiter::attempts($key);

            if ($count === intdiv($limit, 2)) {
                $this->alerts->building($building, 'pin-building', 'hour', $count, $limit);
            }

            if ($count === $limit) {
                Cache::put('pin-building-paused:'.$building->id, true, now()->addMinutes((int) $cfg['building_pause_minutes']));
                $this->alerts->building($building, 'pin-building', 'hour', $count, $limit);
            }
        }

        $this->monitorGlobal(unknown: false);
    }

    /**
     * Alert-only global monitor (never blocks).
     */
    private function monitorGlobal(bool $unknown): void
    {
        $cfg = config('majas.pin');
        $hour = now()->format('YmdH');

        $failed = $this->bump("pin-global:failed:{$hour}");
        if ($failed === (int) $cfg['global_failed_alert_per_hour']) {
            $this->alerts->platform('pin-global', 'hour', $failed, (int) $cfg['global_failed_alert_per_hour']);
        }

        if ($unknown) {
            $unknownCount = $this->bump("pin-global:unknown:{$hour}");
            if ($unknownCount === (int) $cfg['global_unknown_alert_per_hour']) {
                $this->alerts->platform('pin-global-unknown', 'hour', $unknownCount, (int) $cfg['global_unknown_alert_per_hour']);
            }
        }
    }

    private function bump(string $key): int
    {
        Cache::add($key, 0, now()->addHours(2));

        return (int) Cache::increment($key);
    }
}

<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditLogger;
use App\Auth\Passkeys\PasskeyService;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\SecurityChangedNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * "Uzticēties šai ierīcei 30 dienas" (ADR 0006). After a real MFA (TOTP code or passkey) the person may mark this
 * browser as trusted; the normal step-up is then not asked again on it for 30 days.
 *
 * - Cookie `__Host-mp_trusted` = "{row id}|{32-byte secret}" (Secure, HttpOnly, SameSite=Lax, Path=/, no Domain →
 *   sibling *.coredigify.com sites can neither read nor toss it; Laravel additionally encrypts it with APP_KEY).
 *   Only SHA-256(secret) is stored.
 * - Bound to the user AND the session epoch: a row counts only while trusted_devices.auth_epoch = users.auth_epoch,
 *   so every credential change, "Aizmirst ierīces", a removed passkey, a role revoke and erasure end ALL trusted
 *   devices at once (SessionEpoch::bump).
 * - Never satisfies a dangerous action (break-glass, granting a role to someone else, adding / removing a sign-in
 *   factor): StepUpService::hasFreshStrongMfa ignores it.
 * - Created, used (once per session) and revoked rows are written to the platform audit chain; creating one mails the
 *   account (SecurityChangedNotice).
 */
final class TrustedDevices
{
    public const COOKIE = '__Host-mp_trusted';

    public const DAYS = 30;

    public const MAX_PER_USER = 10;

    /**
     * Mark this browser as trusted. Only right after a real MFA of THIS session (passkey / TOTP in the last 10
     * minutes) — a trusted device can never mint another one.
     */
    public function trust(User $user, Request $request, string $method): ?TrustedDevice
    {
        $state = session(StepUpService::SESSION_KEY);
        if (! is_array($state) || (int) ($state['user'] ?? 0) !== (int) $user->id
            || ! in_array($state['method'] ?? null, StepUpService::STRONG_METHODS, true)
            || (int) ($state['at'] ?? 0) < now()->subMinutes(10)->getTimestamp()) {
            return null;
        }

        $current = $this->current($user, $request);
        $current?->delete();

        // Housekeeping: dead rows of this account (other epoch / expired) and the cap on live ones.
        TrustedDevice::query()->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('auth_epoch', '!=', (int) $user->auth_epoch)->orWhere('expires_at', '<=', now()))
            ->delete();
        $live = TrustedDevice::query()->validFor($user)->orderByDesc('id')->pluck('id');
        if ($live->count() >= self::MAX_PER_USER) {
            TrustedDevice::query()->whereIn('id', $live->slice(self::MAX_PER_USER - 1)->all())->delete();
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $device = TrustedDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $secret),
            'auth_epoch' => (int) $user->auth_epoch,
            'label' => PasskeyService::deviceLabel($request->userAgent()),
            'method' => $method === 'passkey' ? 'passkey' : 'totp',
            'expires_at' => now()->addDays(self::DAYS),
        ]);

        Cookie::queue(Cookie::make(self::COOKIE, $device->id.'|'.$secret, self::DAYS * 24 * 60, '/', null, true, true, false, 'lax'));

        AuditLogger::record('account.device_trusted', $device, [(int) $user->id], data: ['device_id' => (int) $device->id, 'method' => $device->method], platform: true, actorRole: 'self');
        $user->notify(new SecurityChangedNotice('device_trusted'));

        return $device;
    }

    /**
     * This browser's trusted-device row for $user, if its cookie is genuine and the row still counts.
     */
    public function current(User $user, Request $request): ?TrustedDevice
    {
        $value = $request->cookie(self::COOKIE);

        if (! is_string($value) || ! preg_match('/^(\d{1,18})\|([A-Za-z0-9_-]{43})$/', $value, $m)) {
            return null;
        }

        $device = TrustedDevice::query()->validFor($user)->whereKey((int) $m[1])->first();

        return $device !== null && hash_equals($device->token_hash, hash('sha256', $m[2])) ? $device : null;
    }

    public function markUsed(TrustedDevice $device): void
    {
        $device->forceFill(['last_used_at' => now()])->save();
        AuditLogger::record('account.trusted_device_used', $device, [(int) $device->user_id], data: ['device_id' => (int) $device->id], platform: true, actorRole: 'self');
    }

    public function revoke(User $user, int $deviceId): void
    {
        $deleted = TrustedDevice::query()->where('user_id', $user->id)->whereKey($deviceId)->delete();

        if ($deleted > 0) {
            AuditLogger::record('account.device_untrusted', ['trusted_device', $deviceId], [(int) $user->id], data: ['device_id' => $deviceId], platform: true, actorRole: 'self');
        }
    }

    public function revokeAll(User $user): void
    {
        $deleted = TrustedDevice::query()->where('user_id', $user->id)->delete();

        if ($deleted > 0) {
            AuditLogger::record('account.device_untrusted', $user, [(int) $user->id], data: ['count' => $deleted], platform: true, actorRole: 'self');
        }

        Cookie::queue(Cookie::forget(self::COOKIE, '/', null));
    }
}

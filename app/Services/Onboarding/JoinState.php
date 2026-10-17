<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\MembershipRole;
use App\Tenancy\ResolvedInvite;
use Illuminate\Http\Request;

/**
 * Server-side (session) state of the /join flow between steps: resolved invite (no secret), chosen role,
 * accepted notice versions and the 18+ confirmation. Never contains the typed code.
 */
final class JoinState
{
    public const KEY = 'mp.join';

    public function __construct(
        public ResolvedInvite $invite,
        public ?MembershipRole $role = null,
        public ?string $platformNoticeVersion = null,
        public bool $adultConfirmed = false,
        public int $startedAt = 0,
    ) {
        $this->startedAt = $startedAt ?: now()->getTimestamp();
    }

    public function save(Request $request): void
    {
        session()->put(self::KEY, [
            'invite' => $this->invite->toArray(),
            'role' => $this->role?->value,
            'platform_notice_version' => $this->platformNoticeVersion,
            'adult' => $this->adultConfirmed,
            'started_at' => $this->startedAt,
        ]);
    }

    public static function get(Request $request): ?self
    {
        $data = session()->get(self::KEY);

        if (! is_array($data) || ! isset($data['invite']) || ! is_array($data['invite'])) {
            return null;
        }

        // The join flow expires after 60 minutes.
        if ((int) ($data['started_at'] ?? 0) < now()->subMinutes(60)->getTimestamp()) {
            session()->forget(self::KEY);

            return null;
        }

        return new self(
            invite: ResolvedInvite::fromArray($data['invite']),
            role: isset($data['role']) ? MembershipRole::tryFrom((string) $data['role']) : null,
            platformNoticeVersion: isset($data['platform_notice_version']) ? (string) $data['platform_notice_version'] : null,
            adultConfirmed: (bool) ($data['adult'] ?? false),
            startedAt: (int) ($data['started_at'] ?? 0),
        );
    }

    public static function pull(Request $request): ?self
    {
        $state = self::get($request);
        session()->forget(self::KEY);

        return $state;
    }

    public static function forget(Request $request): void
    {
        session()->forget(self::KEY);
    }
}

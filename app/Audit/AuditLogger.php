<?php

declare(strict_types=1);

namespace App\Audit;

use App\Casts\PgArray;
use App\Support\Identifiers;
use App\Tenancy\CurrentBuilding;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Append-only audit writer (MVP-SPEC §0): AuditLogger::record(action, subject, subjectUserIds[], reason?, data[]).
 *
 * The chain is the current building (chain_key = building_id) or the platform chain (0) when no building context
 * is set or $platform is true. id, created_at, prev_hash and hash are assigned by the BEFORE INSERT trigger.
 * `data` must never hold plaintext personal data: keys that look like identifiers or secrets are refused.
 */
final class AuditLogger
{
    /** Keys refused in `data` (plaintext PII / secrets never enter the audit log). */
    private const FORBIDDEN_KEYS = [
        'email', 'e_mail', 'phone', 'tel', 'plate', 'plate_number', 'code', 'invite_code', 'token', 'pin',
        'password', 'handle', 'login_handle', 'name', 'display_name', 'ip', 'signature', 'secret',
    ];

    /**
     * @param  Model|array{0: string, 1: int|string}|null  $subject
     * @param  list<int>  $subjectUserIds
     * @param  array<string, mixed>  $data
     */
    public static function record(
        string $action,
        Model|array|null $subject = null,
        array $subjectUserIds = [],
        ?string $reasonCode = null,
        array $data = [],
        ?string $reasonText = null,
        bool $platform = false,
        ?string $actorRole = null,
        ?int $actorUserId = null,
        ?ConnectionInterface $connection = null,
    ): int {
        self::assertSafe($data);

        $buildingId = (! $platform && CurrentBuilding::has()) ? CurrentBuilding::id() : null;

        [$subjectType, $subjectId] = match (true) {
            $subject instanceof Model => [$subject->getMorphClass(), (string) $subject->getKey()],
            is_array($subject) => [(string) $subject[0], (string) $subject[1]],
            default => [null, null],
        };

        $actorUserId ??= auth()->id() !== null ? (int) auth()->id() : null;

        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        $row = [
            'chain_key' => $buildingId ?? 0,
            'building_id' => $buildingId,
            'actor_user_id' => $actorUserId,
            'actor_role' => $actorRole ?? ($actorUserId === null ? 'system' : null),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_user_ids' => PgArray::literal(array_values(array_unique(array_map('intval', $subjectUserIds))), 'int'),
            'reason_code' => $reasonCode,
            'reason_text' => $reasonText !== null ? mb_substr($reasonText, 0, 500) : null,
            'data' => json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip_hmac' => $request?->ip() !== null ? Identifiers::ipHmac((string) $request->ip()) : null,
            'ua_hash' => $request?->userAgent() !== null ? substr(hash('sha256', (string) $request->userAgent()), 0, 32) : null,
        ];

        // An explicit connection (e.g. the retention role inside prune:vehicles) inserts without RETURNING (no SELECT
        // grant needed for the id); the default path returns the trigger-assigned id.
        if ($connection !== null) {
            $connection->table('audit_entries')->insert($row);

            return 0;
        }

        return (int) DB::table('audit_entries')->insertGetId($row);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function assertSafe(array $data): void
    {
        array_walk_recursive($data, static function (mixed $value, int|string $key): void {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException("Audit data key [{$key}] is not allowed (no plaintext PII in the audit log).");
            }
        });
    }
}

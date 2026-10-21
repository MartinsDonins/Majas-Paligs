<?php

declare(strict_types=1);

namespace App\Platform;

use App\Audit\AuditLogger;
use App\Auth\StepUpService;
use App\Models\Building;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * SANCTIONED cross-tenant READ for platform admins (PLANNING §4.2 item 4): reason required, fresh MFA,
 * audit-logged in the platform chain AND the building chain, executed inside a READ ONLY transaction.
 */
final class BreakGlass
{
    /**
     * @template T
     *
     * @param  callable(Building): T  $callback
     * @return T
     */
    public static function read(User $admin, Building $building, string $reason, callable $callback): mixed
    {
        if (! $admin->isPlatformAdmin()) {
            throw new AuthorizationException('Break-glass requires platform_admin.');
        }

        // A dangerous action (ADR 0006): a passkey / TOTP of THIS session — a trusted device is not enough.
        if (! app(StepUpService::class)->hasFreshStrongMfa($admin)) {
            throw new AuthorizationException('Break-glass requires a fresh passkey or TOTP step-up.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException('A break-glass reason of at least 10 characters is required.');
        }

        AuditLogger::record(
            action: 'platform.break_glass',
            subject: $building,
            reasonCode: 'break_glass',
            reasonText: $reason,
            data: ['building_id' => (int) $building->id],
            platform: true,
            actorRole: 'platform_admin',
        );

        return CurrentBuilding::runFor($building, static function (Building $b) use ($callback, $reason): mixed {
            AuditLogger::record(
                action: 'platform.break_glass',
                subject: $b,
                reasonCode: 'break_glass',
                reasonText: $reason,
                actorRole: 'platform_admin',
            );

            if (DB::transactionLevel() > 0) {
                // Already inside a transaction (e.g. a test wrapper): READ ONLY can no longer be set.
                return $callback($b);
            }

            return DB::transaction(static function () use ($callback, $b): mixed {
                DB::statement('SET TRANSACTION READ ONLY');

                return $callback($b);
            });
        });
    }
}

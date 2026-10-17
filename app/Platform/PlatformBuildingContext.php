<?php

declare(strict_types=1);

namespace App\Platform;

use App\Audit\AuditLogger;
use App\Auth\StepUpService;
use App\Models\Building;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Named platform-admin building context (PLANNING §4.2 item 7, MVP-SPEC A.5) for the `platform` panel
 * (BuildingGeneratorPage, BuildingResource structure actions) and console seeders.
 *
 * - HTTP (an authenticated user): requires platform_admin + a fresh MFA (TOTP) step-up.
 * - Console (no user): only PlatformPurpose::Seed.
 * Writes a platform-chain audit entry {purpose, building_id} and delegates to CurrentBuilding::runFor.
 */
final class PlatformBuildingContext
{
    /**
     * @template T
     *
     * @param  callable(Building): T  $callback
     * @return T
     *
     * @throws AuthorizationException
     */
    public static function run(Building $building, PlatformPurpose $purpose, callable $callback): mixed
    {
        $user = auth()->user();

        if ($user === null) {
            if (! app()->runningInConsole() || $purpose !== PlatformPurpose::Seed) {
                throw new AuthorizationException('Platform building context outside HTTP is allowed only for seeding.');
            }
        } else {
            if (! $user instanceof User || ! $user->isPlatformAdmin()) {
                throw new AuthorizationException('Platform building context requires platform_admin.');
            }

            if (! app(StepUpService::class)->hasFreshMfa($user)) {
                throw new AuthorizationException('Platform building context requires a fresh MFA step-up.');
            }
        }

        AuditLogger::record(
            action: 'platform.building_context',
            subject: $building,
            data: ['purpose' => $purpose->value, 'building_id' => (int) $building->id],
            platform: true,
            actorRole: $user === null ? 'console' : 'platform_admin',
        );

        return CurrentBuilding::runFor($building, $callback);
    }
}

<?php

declare(strict_types=1);

namespace App\Platform;

use App\Audit\AuditLogger;
use App\Auth\StepUpService;
use App\Mcp\McpAccess;
use App\Models\Building;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Named platform-admin building context (PLANNING §4.2 item 7, MVP-SPEC A.5) for the `platform` panel
 * (BuildingGeneratorPage, BuildingResource structure actions) and console seeders.
 *
 * - HTTP (an authenticated user): requires platform_admin + a fresh MFA (TOTP) step-up.
 * - MCP (round 3, ADR 0008): instead of the step-up, a valid MCP bearer token of that platform_admin — any scope for the
 *   read purposes (McpRead, StructureView), and only inside a confirmed write tool of a `write` token for the write
 *   purposes (McpWrite, Generate, RoleBootstrap). McpRead / McpWrite are never reachable without a token.
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

            $mcp = app(McpAccess::class);
            $viaMcp = match ($purpose->mcpLevel()) {
                'read' => $mcp->authorisesRead($user),
                'write' => $mcp->authorisesWrite($user),
                default => false,
            };

            // An MCP purpose is reachable ONLY with an MCP token (never from a session) and vice versa (ADR 0008).
            if (in_array($purpose, [PlatformPurpose::McpRead, PlatformPurpose::McpWrite], true) && ! $viaMcp) {
                throw new AuthorizationException('MCP purposes require an MCP bearer token.');
            }

            if (! $viaMcp && ! app(StepUpService::class)->hasFreshMfa($user)) {
                throw new AuthorizationException('Platform building context requires a fresh MFA step-up.');
            }
        }

        AuditLogger::record(
            action: 'platform.building_context',
            subject: $building,
            data: array_filter([
                'purpose' => $purpose->value,
                'building_id' => (int) $building->id,
                'mcp_token_id' => app(McpAccess::class)->active() ? app(McpAccess::class)->token()?->id : null,
            ], static fn (mixed $v): bool => $v !== null),
            platform: true,
            actorRole: $user === null ? 'console' : (app(McpAccess::class)->active() ? 'platform_admin_mcp' : 'platform_admin'),
        );

        return CurrentBuilding::runFor($building, $callback);
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Audit\AuditLogger;
use App\Mcp\McpAccess;
use App\Mcp\McpPrivacy;
use App\Mcp\McpPrivacyViolation;
use App\Mcp\McpRefusal;
use App\Models\Building;
use App\Models\McpToken;
use App\Models\User;
use App\Platform\PlatformBuildingContext;
use App\Platform\PlatformPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Support\ValidationMessages;
use Throwable;

/**
 * Base of every Kaimiņos MCP tool (docs/MCP.md, ADR 0008). handle() is final and does, for every call:
 *
 * 1. Write tools: the token must have the `write` scope, the call must carry `confirm: true` (a `dry_run: true` call
 *    of a tool that supports it needs neither the elevation nor confirm), and at most WRITES_PER_HOUR writes per token.
 * 2. execute() inside the building context of the tool's `building` argument (PlatformBuildingContext, purpose
 *    mcp_read / mcp_write — a token stands in for the session step-up there and nowhere else).
 * 3. McpPrivacy::assertClean() over the result: personal data withholds the whole answer.
 * 4. One platform-chain audit row `mcp.call` {tool, token_id, building_id, scope, outcome, dry_run} — also for refusals
 *    and errors. Arguments are never logged (they may contain an invitee's e-mail).
 */
abstract class McpTool extends Tool
{
    public const WRITES_PER_HOUR = 30;

    /** `read` or `write`. */
    protected string $scope = McpToken::SCOPE_READ;

    /** Write tools that answer `dry_run: true` without writing (generate_building_from_series). */
    protected bool $supportsDryRun = false;

    private ?int $auditBuildingId = null;

    /**
     * @return array<string, mixed> the structured result (counts, statuses, ids, masked hints only)
     *
     * @throws McpRefusal|ValidationException|AuthorizationException
     */
    abstract protected function execute(Request $request, User $actor): array;

    final public function handle(Request $request, McpAccess $access): Response|ResponseFactory
    {
        $this->auditBuildingId = null;
        $user = $access->user();
        $token = $access->token();
        $write = $this->scope === McpToken::SCOPE_WRITE;
        $dryRun = $write && $this->supportsDryRun && $request->get('dry_run', true) !== false;
        $outcome = 'ok';

        try {
            if ($user === null || $token === null) {
                throw new McpRefusal('unauthenticated', 'Unauthenticated.');
            }

            if ($write && ! $token->allows(McpToken::SCOPE_WRITE)) {
                throw new McpRefusal('scope', 'This tool changes data: use a token with the "write" scope (create one in /admin/platform → MCP piekļuve).');
            }

            if ($write && ! $dryRun && $request->get('confirm') !== true) {
                throw new McpRefusal('confirm', 'This tool changes data. Repeat the call with "confirm": true after the owner approved this exact change.');
            }

            if ($write && ! $dryRun) {
                $key = 'mcp-write:'.$token->id;
                if (RateLimiter::tooManyAttempts($key, self::WRITES_PER_HOUR)) {
                    throw new McpRefusal('rate_limited', 'Write limit reached ('.self::WRITES_PER_HOUR.' per hour per token). Try again later.');
                }
                RateLimiter::hit($key, 3600);
            }

            $result = $write && ! $dryRun
                ? $access->elevated(fn (): array => $this->execute($request, $user))
                : $this->execute($request, $user);

            McpPrivacy::assertClean($result);

            return Response::structured($result);
        } catch (McpRefusal $e) {
            $outcome = 'refused:'.$e->reason;

            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            $outcome = 'invalid';

            return Response::error(ValidationMessages::from($e));
        } catch (AuthorizationException $e) {
            $outcome = 'denied';

            return Response::error('Not allowed: '.$e->getMessage());
        } catch (McpPrivacyViolation) {
            $outcome = 'withheld';

            return Response::error('The result was withheld: it would have contained personal data. Nothing is shown over MCP but counts, statuses and masked hints.');
        } catch (Throwable $e) {
            $outcome = 'error';
            report($e);

            return Response::error('Internal error ('.class_basename($e).'). See app_status → recent errors.');
        } finally {
            AuditLogger::record(
                action: 'mcp.call',
                subject: $token !== null ? $token : null,
                subjectUserIds: [],
                data: array_filter([
                    'tool' => $this->name(),
                    'token_id' => $token?->id,
                    'building_id' => $this->auditBuildingId,
                    'scope' => $this->scope,
                    'outcome' => $outcome,
                    'dry_run' => $write ? $dryRun : null,
                ], static fn (mixed $v): bool => $v !== null),
                platform: true,
                actorRole: 'platform_admin_mcp',
                actorUserId: $user !== null ? (int) $user->id : null,
            );
        }
    }

    /**
     * The building of the call's `building` argument (slug), or a refusal that lists how to find one.
     *
     * @throws McpRefusal
     */
    protected function building(Request $request): Building
    {
        $slug = $request->get('building');
        if (! is_string($slug) || $slug === '' || strlen($slug) > 120) {
            throw new McpRefusal('building', 'Argument "building" (the building slug) is required. Call buildings_list to see the slugs.');
        }

        $building = Building::query()->where('slug', $slug)->first();
        if ($building === null) {
            throw new McpRefusal('building', "Unknown building \"{$slug}\". Call buildings_list to see the slugs.");
        }

        $this->auditBuildingId = (int) $building->id;

        return $building;
    }

    /**
     * Run $callback in the building's context (read: mcp_read; write tools: mcp_write unless another purpose is given).
     *
     * @template T
     *
     * @param  callable(Building): T  $callback
     * @return T
     */
    protected function inBuilding(Building $building, callable $callback, ?PlatformPurpose $purpose = null): mixed
    {
        $this->auditBuildingId = (int) $building->id;

        return PlatformBuildingContext::run(
            $building,
            $purpose ?? ($this->scope === McpToken::SCOPE_WRITE ? PlatformPurpose::McpWrite : PlatformPurpose::McpRead),
            $callback,
        );
    }

    /**
     * A tool that visited several buildings is not "about" one of them (its mcp.call row carries no building_id; the
     * per-building platform.building_context rows remain).
     */
    protected function forgetAuditBuilding(): void
    {
        $this->auditBuildingId = null;
    }

    /**
     * A timestamp (Carbon, DB string or null) as ISO 8601 in UTC.
     */
    protected static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof \DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value))->utc()->toIso8601String();
    }

    /**
     * Small helper for limits: an integer argument clamped to [min, max].
     */
    protected function intArg(Request $request, string $key, int $default, int $min, int $max): int
    {
        $value = $request->get($key, $default);

        return max($min, min($max, is_numeric($value) ? (int) $value : $default));
    }
}

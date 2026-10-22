<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\McpToken;
use App\Models\User;
use LogicException;

/**
 * Per-request MCP authorisation state (scoped binding, flushed between requests / jobs). Set ONLY by
 * App\Mcp\Http\AuthenticateMcpToken after a valid bearer token; read by the tools, PlatformBuildingContext and
 * StepUpService.
 *
 * The write elevation is the ONE place where an MCP call stands in for a session step-up (ADR 0008): it is active only
 * inside McpTool::handle() of a write tool, after the token's `write` scope, `confirm: true` and the write rate limit
 * were checked, and only for the token's own user. While it is active, StepUpService treats that user as freshly
 * stepped up (mfa and strong) and PlatformBuildingContext accepts the MCP write purposes. Outside it nothing changes:
 * a read token can never satisfy a step-up.
 */
final class McpAccess
{
    private ?McpToken $token = null;

    private ?User $user = null;

    private bool $writeElevated = false;

    public function authenticate(McpToken $token, User $user): void
    {
        if ($this->token !== null && $this->token->id !== $token->id) {
            throw new LogicException('MCP access is already bound to another token in this request.');
        }

        $this->token = $token;
        $this->user = $user;
    }

    public function active(): bool
    {
        return $this->token !== null && $this->user !== null;
    }

    public function token(): ?McpToken
    {
        return $this->token;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function allows(string $scope): bool
    {
        return $this->token !== null && $this->token->allows($scope);
    }

    /**
     * True when $user is this request's MCP user (any scope): PlatformBuildingContext read purposes.
     */
    public function authorisesRead(User $user): bool
    {
        return $this->active() && (int) $this->user?->id === (int) $user->id;
    }

    /**
     * True only inside a confirmed write tool of a `write` token of $user.
     */
    public function authorisesWrite(User $user): bool
    {
        return $this->writeElevated && $this->authorisesRead($user) && $this->allows(McpToken::SCOPE_WRITE);
    }

    /**
     * Run $callback with the write elevation on (McpTool only, after scope + confirm + rate limit checks).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function elevated(callable $callback): mixed
    {
        if (! $this->active() || ! $this->allows(McpToken::SCOPE_WRITE)) {
            throw new LogicException('MCP write elevation needs a write token.');
        }

        $previous = $this->writeElevated;
        $this->writeElevated = true;

        try {
            return $callback();
        } finally {
            $this->writeElevated = $previous;
        }
    }
}

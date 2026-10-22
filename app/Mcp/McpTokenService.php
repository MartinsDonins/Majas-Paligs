<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Audit\AuditLogger;
use App\Auth\StepUpService;
use App\Models\McpToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Issue / resolve / revoke MCP personal access tokens (docs/MCP.md, ADR 0008).
 *
 * Format: `kmcp_<token id, 26 chars>_<secret, 43 chars base64url = 32 random bytes>`. The id selects the row, the
 * secret is compared as SHA-256 with hash_equals. Issuing needs platform_admin + a STRONG step-up (passkey / TOTP of
 * this session — a token is a new credential, like adding a sign-in factor); revoking needs mfa. Both are audited in
 * the platform chain with ids, scopes and expiry only — never the secret.
 */
final class McpTokenService
{
    public const PREFIX = 'kmcp_';

    public const MAX_ACTIVE_PER_USER = 5;

    public const MAX_READ_DAYS = 365;

    public const MAX_WRITE_DAYS = 30;

    public function __construct(private readonly StepUpService $stepUp) {}

    /**
     * @param  list<string>  $scopes  ['read'] or ['read', 'write']
     * @return array{token: McpToken, plaintext: string}
     *
     * @throws AuthorizationException|InvalidArgumentException
     */
    public function issue(User $actor, string $label, array $scopes, ?int $validDays): array
    {
        if (! $actor->isPlatformAdmin() || $actor->isTombstone()) {
            throw new AuthorizationException('Only a platform_admin creates MCP tokens.');
        }

        if (! $this->stepUp->hasFreshStrongMfa($actor)) {
            throw new AuthorizationException('Creating an MCP token requires a fresh passkey or TOTP confirmation.');
        }

        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 60) {
            throw new InvalidArgumentException(__('admin.mcp.error.label'));
        }

        $scopes = array_values(array_unique(['read', ...array_intersect($scopes, [McpToken::SCOPE_READ, McpToken::SCOPE_WRITE])]));
        sort($scopes);
        $write = in_array(McpToken::SCOPE_WRITE, $scopes, true);

        $max = $write ? self::MAX_WRITE_DAYS : self::MAX_READ_DAYS;
        if ($write && $validDays === null) {
            $validDays = self::MAX_WRITE_DAYS;
        }
        if ($validDays !== null && ($validDays < 1 || $validDays > $max)) {
            throw new InvalidArgumentException(__('admin.mcp.error.days', ['max' => $max]));
        }

        if (McpToken::query()->usable()->where('user_id', $actor->id)->count() >= self::MAX_ACTIVE_PER_USER) {
            throw new InvalidArgumentException(__('admin.mcp.error.too_many', ['max' => self::MAX_ACTIVE_PER_USER]));
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $token = McpToken::query()->create([
            'user_id' => $actor->id,
            'label' => $label,
            'scopes' => $scopes,
            'token_hash' => hash('sha256', $secret, true),
            'token_hint' => substr($secret, -4),
            'auth_epoch' => (int) $actor->auth_epoch,
            'expires_at' => $validDays !== null ? now()->addDays($validDays) : null,
        ]);

        AuditLogger::record('mcp.token.create', $token, [(int) $actor->id], data: [
            'token_id' => $token->id,
            'scopes' => $scopes,
            'valid_days' => $validDays,
        ], platform: true, actorRole: 'platform_admin', actorUserId: (int) $actor->id);

        return ['token' => $token, 'plaintext' => self::PREFIX.strtolower($token->id).'_'.$secret];
    }

    /**
     * The usable token for a presented bearer value, or null (unknown, malformed, wrong secret, revoked, expired,
     * owner no longer platform_admin, owner's credentials changed). One neutral answer for every case.
     */
    public function resolve(#[\SensitiveParameter] string $presented): ?McpToken
    {
        if (strlen($presented) > 120 || preg_match('/^kmcp_([0-9a-hjkmnp-tv-z]{26})_([A-Za-z0-9_-]{43})$/', $presented, $m) !== 1) {
            return null;
        }

        /** @var McpToken|null $token */
        $token = McpToken::query()->with('user')->whereKey($m[1])->first();

        $expected = hash('sha256', $m[2], true);
        if ($token === null) {
            return null;
        }

        if (! hash_equals((string) $token->token_hash, $expected) || ! $token->isUsable()) {
            return null;
        }

        $user = $token->user;
        if (! $user instanceof User || ! $user->isPlatformAdmin() || $user->isTombstone() || (int) $user->auth_epoch !== (int) $token->auth_epoch) {
            return null;
        }

        return $token;
    }

    /**
     * Record use at most once a minute (no write per call).
     */
    public function touch(McpToken $token): void
    {
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            McpToken::query()->whereKey($token->id)->update(['last_used_at' => now()]);
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function revoke(User $actor, McpToken $token): void
    {
        if (! $actor->isPlatformAdmin() || (int) $token->user_id !== (int) $actor->id) {
            throw new AuthorizationException('A platform_admin revokes only their own MCP tokens.');
        }

        if (! $this->stepUp->hasFreshMfa($actor)) {
            throw new AuthorizationException('Revoking an MCP token requires a fresh second factor.');
        }

        $claimed = McpToken::query()->whereKey($token->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'updated_at' => now()]);

        if ($claimed === 1) {
            AuditLogger::record('mcp.token.revoke', $token, [(int) $actor->id], data: ['token_id' => $token->id],
                platform: true, actorRole: 'platform_admin', actorUserId: (int) $actor->id);
        }
    }

    /**
     * Tokens of $user for the "MCP piekļuve" page (no secrets).
     *
     * @return list<array{id: string, label: string, scopes: list<string>, hint: string, created_at: CarbonImmutable, expires_at: ?CarbonImmutable, last_used_at: ?CarbonImmutable, revoked_at: ?CarbonImmutable, state: string}>
     */
    public function listFor(User $user): array
    {
        return McpToken::query()->where('user_id', $user->id)->latest('created_at')->limit(50)->get()
            ->map(fn (McpToken $t): array => [
                'id' => $t->id,
                'label' => $t->label,
                'scopes' => $t->scopes,
                'hint' => $t->token_hint,
                'created_at' => $t->created_at,
                'expires_at' => $t->expires_at,
                'last_used_at' => $t->last_used_at,
                'revoked_at' => $t->revoked_at,
                'state' => match (true) {
                    $t->revoked_at !== null => 'revoked',
                    $t->expires_at !== null && ! $t->expires_at->isFuture() => 'expired',
                    (int) $t->auth_epoch !== (int) $user->auth_epoch => 'stale',
                    default => 'active',
                },
            ])->values()->all();
    }
}

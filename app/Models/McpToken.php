<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform_admin's personal access token for the in-app MCP server (docs/MCP.md, ADR 0008). Platform data, not a
 * tenant table. The secret is shown once; only SHA-256(secret) is stored. A token works only while: not revoked, not
 * expired, its owner is still platform_admin (not a tombstone) and the owner's auth_epoch is unchanged.
 *
 * @property string $id
 * @property int $user_id
 * @property string $label
 * @property list<string> $scopes
 * @property string $token_hash
 * @property string $token_hint
 * @property int $auth_epoch
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by
 * @property CarbonImmutable $created_at
 * @property-read User $user
 */
class McpToken extends Model
{
    use HasUlids;

    public const SCOPE_READ = 'read';

    public const SCOPE_WRITE = 'write';

    protected $fillable = [
        'user_id', 'label', 'scopes', 'token_hash', 'token_hint', 'auth_epoch', 'expires_at', 'last_used_at',
        'revoked_at', 'revoked_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'token_hash' => Bytea::class,
            'auth_epoch' => 'integer',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<McpToken>  $query
     * @return Builder<McpToken>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Uzticēties šai ierīcei 30 dienas" (ADR 0006): created only right after a real MFA (TOTP or passkey); while it is
 * valid the device skips the normal step-up. It never satisfies a dangerous action (StepUpService::hasFreshStrongMfa).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property int $auth_epoch
 * @property string $label
 * @property string $method
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 */
class TrustedDevice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'auth_epoch' => 'integer',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * Rows that still count for $user: same session epoch, not expired.
     *
     * @param  Builder<TrustedDevice>  $query
     * @return Builder<TrustedDevice>
     */
    public function scopeValidFor(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id)
            ->where('auth_epoch', (int) $user->auth_epoch)
            ->where('expires_at', '>', now());
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginTokenPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hashed single-use login / step-up token (+ 6-digit code). Platform account data (CoreDigify controller).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $email_hmac
 * @property string|null $token_hash
 * @property string|null $code_hash
 * @property LoginTokenPurpose $purpose
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property string|null $ip_hmac
 * @property string|null $role_invitation_id
 */
class LoginToken extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'email_hmac', 'token_hash', 'code_hash', 'purpose', 'attempts', 'expires_at', 'used_at', 'ip_hmac', 'role_invitation_id'];

    protected $hidden = ['token_hash', 'code_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => LoginTokenPurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}

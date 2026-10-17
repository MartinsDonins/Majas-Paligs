<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invite code of an apartment. Only the HMAC of the code is stored (MVP-SPEC A.5 InviteCodeService).
 * Read without building scope ONLY by App\Tenancy\InviteResolver.
 *
 * @property string $id
 * @property int $building_id
 * @property int $apartment_id
 * @property string $code_hmac
 * @property int $max_uses
 * @property int $uses
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $created_by
 * @property string|null $batch_ref
 * @property-read Apartment $apartment
 */
class ApartmentInvite extends Model
{
    use BelongsToBuilding;
    use HasUlids;

    protected $fillable = ['building_id', 'apartment_id', 'code_hmac', 'max_uses', 'uses', 'expires_at', 'revoked_at', 'created_by', 'batch_ref'];

    protected $hidden = ['code_hmac'];

    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'uses' => 'integer',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Apartment, $this> */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    public function isUsable(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->revoked_at === null
            && $this->expires_at->isAfter($now)
            && $this->uses < $this->max_uses;
    }
}

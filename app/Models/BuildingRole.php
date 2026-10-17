<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BuildingRoleType;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delegated role in a building (PLANNING §4.4). Expires after 12 months unless re-confirmed.
 *
 * @property int $id
 * @property int $building_id
 * @property int $user_id
 * @property BuildingRoleType $role
 * @property int|null $section_id
 * @property int|null $granted_by
 * @property CarbonImmutable $granted_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $mandate_ref
 * @property-read User $user
 * @property-read Section|null $section
 */
class BuildingRole extends Model
{
    use BelongsToBuilding;

    protected $fillable = ['building_id', 'user_id', 'role', 'section_id', 'granted_by', 'granted_at', 'expires_at', 'revoked_at', 'mandate_ref'];

    protected function casts(): array
    {
        return [
            'role' => BuildingRoleType::class,
            'granted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Not revoked and not expired.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}

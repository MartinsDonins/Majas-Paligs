<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use App\Enums\VehicleSource;
use App\Enums\VehicleStatus;
use App\Gdpr\GuardedByParkingGate;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vehicle of an apartment (PARK-001). The plate exists only as PlateVault ciphertext (AEAD bound to building +
 * table + this ULID) and a per-building blind index. Resident declarations start `pending`; only an officer/admin
 * action sets `active` (partial UNIQUE on the active bidx). Deleted only through VehicleRemovalService.
 *
 * @property string $id
 * @property int $building_id
 * @property int $apartment_id
 * @property string $plate_ciphertext
 * @property string $plate_bidx
 * @property int $plate_key_version
 * @property string $country
 * @property bool $is_company_car
 * @property VehicleStatus $status
 * @property bool $conflict
 * @property VehicleSource $source
 * @property int|null $declared_by_user_id
 * @property CarbonImmutable|null $verified_at
 * @property int|null $verified_by
 * @property CarbonImmutable|null $reconfirm_due_at
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable $created_at
 * @property-read Apartment $apartment
 */
class Vehicle extends Model
{
    use BelongsToBuilding;
    use GuardedByParkingGate;
    use HasUlids;

    protected $fillable = [
        'id', 'building_id', 'apartment_id', 'plate_ciphertext', 'plate_bidx', 'plate_key_version', 'country',
        'is_company_car', 'status', 'conflict', 'source', 'declared_by_user_id', 'verified_at', 'verified_by',
        'reconfirm_due_at', 'removed_at',
    ];

    protected $hidden = ['plate_ciphertext', 'plate_bidx'];

    protected $attributes = [
        'country' => 'LV',
        'is_company_car' => false,
        'status' => 'pending',
        'conflict' => false,
        'source' => 'self',
    ];

    protected function casts(): array
    {
        return [
            'plate_ciphertext' => Bytea::class,
            'plate_bidx' => Bytea::class,
            'plate_key_version' => 'integer',
            'is_company_car' => 'boolean',
            'conflict' => 'boolean',
            'status' => VehicleStatus::class,
            'source' => VehicleSource::class,
            'verified_at' => 'immutable_datetime',
            'reconfirm_due_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', VehicleStatus::Active->value);
    }

    /**
     * Pending or active (what the apartment "has").
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereIn('status', [VehicleStatus::Pending->value, VehicleStatus::Active->value]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithBidx(Builder $query, string $bidx): Builder
    {
        return $query->where('plate_bidx', Bytea::literal($bidx));
    }

    /** @return BelongsTo<Apartment, $this> */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    /** @return HasMany<Permit, $this> */
    public function permits(): HasMany
    {
        return $this->hasMany(Permit::class);
    }
}

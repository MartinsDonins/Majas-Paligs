<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisputeStatus;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Mans radiators nav šajā stāvadā" — an open dispute freezes the column as "Pārbaudāms" until an admin resolves
 * or rejects it (HEAT-007).
 *
 * @property int $id
 * @property int $building_id
 * @property int $apartment_id
 * @property int|null $room_id
 * @property int $riser_id
 * @property DisputeStatus $status
 * @property int|null $created_by
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $created_at
 * @property-read Riser $riser
 * @property-read Apartment $apartment
 */
class RiserDispute extends Model
{
    use BelongsToBuilding;

    protected $fillable = ['building_id', 'apartment_id', 'room_id', 'riser_id', 'status', 'created_by', 'resolved_by', 'resolved_at'];

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', DisputeStatus::Open->value);
    }

    /** @return BelongsTo<Riser, $this> */
    public function riser(): BelongsTo
    {
        return $this->belongsTo(Riser::class);
    }

    /** @return BelongsTo<Apartment, $this> */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }
}

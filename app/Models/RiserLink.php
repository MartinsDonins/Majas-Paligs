<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToBuilding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $building_id
 * @property int $riser_id
 * @property int $apartment_id
 * @property int|null $room_id
 */
class RiserLink extends Model
{
    use BelongsToBuilding;

    protected $fillable = ['building_id', 'riser_id', 'apartment_id', 'room_id'];

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

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}

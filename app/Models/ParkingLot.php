<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToBuilding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Parking lot (PARK-001). No personal data — allowed at gate L0 (lots and sign drafts are prepared first).
 *
 * @property int $id
 * @property int $building_id
 * @property string $name
 * @property int|null $capacity_estimate
 * @property string|null $signage
 * @property string|null $notes
 * @property int $sort
 */
class ParkingLot extends Model
{
    use BelongsToBuilding;

    protected $fillable = ['building_id', 'name', 'capacity_estimate', 'signage', 'notes', 'sort'];

    protected $attributes = ['sort' => 0];

    protected function casts(): array
    {
        return ['capacity_estimate' => 'integer', 'sort' => 'integer'];
    }

    /** @return HasMany<Sighting, $this> */
    public function sightings(): HasMany
    {
        return $this->hasMany(Sighting::class);
    }
}

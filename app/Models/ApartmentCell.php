<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToBuilding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One grid cell (section, floor, position) → exactly one apartment. A merged apartment owns several cells
 * (MVP-SPEC A.4). Composite primary key: write through ApartmentMerger / BuildingGenerator only.
 *
 * @property int $building_id
 * @property int $section_id
 * @property int $floor
 * @property int $position
 * @property int $apartment_id
 * @property bool $is_primary
 */
class ApartmentCell extends Model
{
    use BelongsToBuilding;

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['building_id', 'section_id', 'floor', 'position', 'apartment_id', 'is_primary'];

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'position' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Apartment, $this> */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }
}

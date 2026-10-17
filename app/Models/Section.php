<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToBuilding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stairwell (kāpņu telpa).
 *
 * @property int $id
 * @property int $building_id
 * @property int $number
 * @property int $first_apartment_no
 * @property int $apartments_per_floor
 * @property int $floors
 * @property string|null $layout_code
 * @property int|null $series_section_layout_id
 */
class Section extends Model
{
    use BelongsToBuilding;

    protected $fillable = ['building_id', 'number', 'first_apartment_no', 'apartments_per_floor', 'floors', 'layout_code', 'series_section_layout_id'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'first_apartment_no' => 'integer',
            'apartments_per_floor' => 'integer',
            'floors' => 'integer',
        ];
    }

    /** @return HasMany<Apartment, $this> */
    public function apartments(): HasMany
    {
        return $this->hasMany(Apartment::class)->orderBy('number');
    }

    /** @return HasMany<Riser, $this> */
    public function risers(): HasMany
    {
        return $this->hasMany(Riser::class);
    }

    /** @return BelongsTo<SeriesSectionLayout, $this> */
    public function seriesLayout(): BelongsTo
    {
        return $this->belongsTo(SeriesSectionLayout::class, 'series_section_layout_id');
    }

    /** @return BelongsToMany<LandParcel, $this> */
    public function landParcels(): BelongsToMany
    {
        return $this->belongsToMany(LandParcel::class, 'section_land_parcel')->withPivot('building_id');
    }

    /**
     * Last apartment number of the section given the generator formula.
     */
    public function lastApartmentNo(): int
    {
        return $this->first_apartment_no + $this->floors * $this->apartments_per_floor - 1;
    }
}

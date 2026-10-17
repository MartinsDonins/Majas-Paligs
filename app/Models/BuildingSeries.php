<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PgArray;
use App\Enums\SeriesStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * Platform data: a building series template (e.g. 602). PLANNING §4.3.
 *
 * @property int $id
 * @property string $code
 * @property list<string> $aliases
 * @property list<int> $floors_typical
 * @property SeriesStatus $status
 */
class BuildingSeries extends Model
{
    use HasTranslations;

    protected $table = 'building_series';

    /** @var list<string> */
    public array $translatable = ['name', 'notes'];

    protected $fillable = [
        'code', 'name', 'aliases', 'years_from', 'years_to', 'floors_typical', 'wall_material',
        'apartments_per_floor_typical', 'notes', 'sources', 'status',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => PgArray::class,
            'floors_typical' => PgArray::class.':int',
            'sources' => 'array',
            'status' => SeriesStatus::class,
            'years_from' => 'integer',
            'years_to' => 'integer',
            'apartments_per_floor_typical' => 'integer',
        ];
    }

    /** @return HasMany<SeriesSectionLayout, $this> */
    public function sectionLayouts(): HasMany
    {
        return $this->hasMany(SeriesSectionLayout::class, 'series_id');
    }

    /** @return HasMany<SeriesApartmentType, $this> */
    public function apartmentTypes(): HasMany
    {
        return $this->hasMany(SeriesApartmentType::class, 'series_id');
    }

    /** @return HasMany<SeriesRiser, $this> */
    public function risers(): HasMany
    {
        return $this->hasMany(SeriesRiser::class, 'series_id');
    }
}

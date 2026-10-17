<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * Section layout preset of a series, e.g. "2-2-3-3": positions [{position, side, apartment_type_code}].
 *
 * @property int $id
 * @property int $series_id
 * @property string $code
 * @property int $apartments_per_floor
 * @property list<array{position: int, side: string, apartment_type_code: string}> $positions
 * @property bool $is_default
 */
class SeriesSectionLayout extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['notes'];

    protected $fillable = ['series_id', 'code', 'apartments_per_floor', 'positions', 'is_default', 'notes'];

    protected function casts(): array
    {
        return [
            'positions' => 'array',
            'is_default' => 'boolean',
            'apartments_per_floor' => 'integer',
        ];
    }

    /** @return BelongsTo<BuildingSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(BuildingSeries::class, 'series_id');
    }

    /** @return HasMany<SeriesRiser, $this> */
    public function risers(): HasMany
    {
        return $this->hasMany(SeriesRiser::class, 'section_layout_id');
    }
}

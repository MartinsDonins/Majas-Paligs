<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * @property int $id
 * @property int $series_id
 * @property string $code
 * @property int $rooms_count
 * @property string|null $area_m2_typical
 */
class SeriesApartmentType extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['label'];

    protected $fillable = ['series_id', 'code', 'rooms_count', 'area_m2_typical', 'label'];

    protected function casts(): array
    {
        return ['rooms_count' => 'integer'];
    }

    /** @return BelongsTo<BuildingSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(BuildingSeries::class, 'series_id');
    }

    /** @return HasMany<SeriesRoom, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(SeriesRoom::class, 'apartment_type_id')->orderBy('sort');
    }
}

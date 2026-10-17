<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * @property int $id
 * @property int $apartment_type_id
 * @property string $code
 * @property int $sort
 * @property int $radiator_count
 * @property bool $towel_dryer
 */
class SeriesRoom extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['label'];

    protected $fillable = ['apartment_type_id', 'code', 'label', 'sort', 'radiator_count', 'towel_dryer'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'radiator_count' => 'integer',
            'towel_dryer' => 'boolean',
        ];
    }

    /** @return BelongsTo<SeriesApartmentType, $this> */
    public function apartmentType(): BelongsTo
    {
        return $this->belongsTo(SeriesApartmentType::class, 'apartment_type_id');
    }
}

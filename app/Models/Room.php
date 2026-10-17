<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToBuilding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * @property int $id
 * @property int $building_id
 * @property int $apartment_id
 * @property string $code
 * @property int $sort
 * @property int $radiator_count
 * @property bool $towel_dryer
 */
class Room extends Model
{
    use BelongsToBuilding;
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['label'];

    protected $fillable = ['building_id', 'apartment_id', 'code', 'label', 'sort', 'radiator_count', 'towel_dryer'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'radiator_count' => 'integer',
            'towel_dryer' => 'boolean',
        ];
    }

    /** @return BelongsTo<Apartment, $this> */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }
}

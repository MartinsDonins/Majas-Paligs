<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RiserConfidence;
use App\Enums\RiserKind;
use App\Enums\Side;
use App\Tenancy\BelongsToBuilding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $building_id
 * @property int $section_id
 * @property string $code
 * @property RiserKind $kind
 * @property int|null $position
 * @property Side|null $side
 * @property int $floors_from
 * @property int $floors_to
 * @property RiserConfidence $confidence
 * @property string|null $source_ref
 * @property int|null $series_riser_id
 */
class Riser extends Model
{
    use BelongsToBuilding;

    protected $fillable = [
        'building_id', 'section_id', 'code', 'kind', 'position', 'side', 'floors_from', 'floors_to',
        'confidence', 'source_ref', 'series_riser_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RiserKind::class,
            'confidence' => RiserConfidence::class,
            'side' => Side::class,
            'position' => 'integer',
            'floors_from' => 'integer',
            'floors_to' => 'integer',
        ];
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return HasMany<RiserLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(RiserLink::class);
    }
}

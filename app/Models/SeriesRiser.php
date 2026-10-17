<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RiserConfidence;
use App\Enums\RiserKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riser template of a series. code_pattern placeholders: {section}, {position}, {side}, {room}.
 * serves: [{position: int, room_code: string|null}] — positions of the stairwell column(s) it serves.
 *
 * @property int $id
 * @property int $series_id
 * @property int|null $section_layout_id
 * @property string $code_pattern
 * @property RiserKind $kind
 * @property list<array{position: int, room_code: string|null}> $serves
 * @property RiserConfidence $confidence
 * @property string|null $source_ref
 */
class SeriesRiser extends Model
{
    protected $fillable = ['series_id', 'section_layout_id', 'code_pattern', 'kind', 'serves', 'confidence', 'source_ref'];

    protected function casts(): array
    {
        return [
            'kind' => RiserKind::class,
            'confidence' => RiserConfidence::class,
            'serves' => 'array',
        ];
    }

    /** @return BelongsTo<BuildingSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(BuildingSeries::class, 'series_id');
    }

    /** @return BelongsTo<SeriesSectionLayout, $this> */
    public function sectionLayout(): BelongsTo
    {
        return $this->belongsTo(SeriesSectionLayout::class, 'section_layout_id');
    }
}

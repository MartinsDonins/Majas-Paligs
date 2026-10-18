<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Projection per (building, plate bidx), written only by SightingStats: sightings in 30 days, distinct reporting
 * apartments in 30 days, days seen in 60 days, repeat flag (≥ 2 independent apartments in 30 days; decays after
 * 30 days without sightings). Officer/admin analytics only.
 *
 * @property int $building_id
 * @property string $plate_bidx
 * @property int $sightings_30d
 * @property int $distinct_reporter_apartments_30d
 * @property int $days_seen_60d
 * @property CarbonImmutable|null $last_seen_at
 * @property bool $is_repeat
 */
class PlateSightingStat extends Model
{
    use BelongsToBuilding;

    protected $table = 'plate_sighting_stats';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['plate_bidx'];

    protected function casts(): array
    {
        return [
            'plate_bidx' => Bytea::class,
            'sightings_30d' => 'integer',
            'distinct_reporter_apartments_30d' => 'integer',
            'days_seen_60d' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'is_repeat' => 'boolean',
        ];
    }
}

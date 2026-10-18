<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToBuilding;
use Illuminate\Database\Eloquent\Model;

/**
 * Projection (RiserAggregator output) per riser and round key, written only by RiserStatusProjector.
 * RESTRICTED columns (floors_reported, top_floor_only) are read only for StaffRiserView — resident read models
 * select an explicit column list without them (HeatingGridQuery::RESIDENT_RISER_COLUMNS).
 *
 * @property int $building_id
 * @property int $section_id
 * @property int $riser_id
 * @property string $round_key
 */
class RiserStatus extends Model
{
    use BelongsToBuilding;

    protected $table = 'riser_status';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}

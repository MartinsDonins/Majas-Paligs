<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToBuilding;
use Illuminate\Database\Eloquent\Model;

/**
 * Projection per (section, position, round key): the column aggregate every resident cell of that column shows.
 * Counts only — no floors, no apartments. Written only by RiserStatusProjector.
 *
 * @property int $building_id
 * @property int $section_id
 * @property int $position
 * @property string $round_key
 */
class ColumnStatus extends Model
{
    use BelongsToBuilding;

    protected $table = 'column_status';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}

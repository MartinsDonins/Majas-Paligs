<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RiserCheckResult;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Pārbaudīts" — a technician/admin check of a riser (HEAT-007). A check newer than the reports sets the riser's
 * checked_state.
 *
 * @property int $id
 * @property int $building_id
 * @property int $riser_id
 * @property RiserCheckResult $result
 * @property CarbonImmutable $checked_at
 * @property int|null $recorded_by
 * @property string|null $note_private
 */
class RiserCheck extends Model
{
    use BelongsToBuilding;

    protected $fillable = ['building_id', 'riser_id', 'result', 'checked_at', 'recorded_by', 'note_private'];

    protected function casts(): array
    {
        return [
            'result' => RiserCheckResult::class,
            'checked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Riser, $this> */
    public function riser(): BelongsTo
    {
        return $this->belongsTo(Riser::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use App\Enums\LookupReason;
use App\Gdpr\GuardedByParkingGate;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only lookup log (PARK-002): every lookup, declaration and sighting writes one row (bidx + yes/no + reason);
 * the rows are also the quota counter. UPDATE is trigger-rejected, DELETE only by majas_retention after 6 months.
 *
 * @property int $id
 * @property int $building_id
 * @property int|null $user_id
 * @property string $plate_bidx
 * @property bool $result
 * @property LookupReason $reason_code
 * @property CarbonImmutable $created_at
 */
class PlateLookup extends Model
{
    use BelongsToBuilding;
    use GuardedByParkingGate;

    public const UPDATED_AT = null;

    protected $fillable = ['building_id', 'user_id', 'plate_bidx', 'result', 'reason_code'];

    protected $hidden = ['plate_bidx'];

    protected function casts(): array
    {
        return [
            'plate_bidx' => Bytea::class,
            'result' => 'boolean',
            'reason_code' => LookupReason::class,
        ];
    }
}

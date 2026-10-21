<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use App\Enums\ObservationSource;
use App\Gdpr\GuardedByParkingGate;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This car was seen with this guest permit" (PARK-011, ROUND2-SPEC §6.1, D8). Guest permits stay NOT plate-bound:
 * an observation is evidence for the "guest permit always the same car" flag, never a binding. The plate exists only
 * as PlateVault ciphertext (AD table `permit_plate_observations`) + the building's blind index. Guest permits only
 * (DB trigger), gate L1 (model guard), kept 6 months (prune:permit-observations), re-keyed by plates:rekey.
 *
 * @property string $id
 * @property int $building_id
 * @property int $permit_id
 * @property string $plate_ciphertext
 * @property string $plate_bidx
 * @property int $plate_key_version
 * @property CarbonImmutable $observed_on
 * @property ObservationSource $source
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Permit $permit
 */
class PermitPlateObservation extends Model
{
    use BelongsToBuilding;
    use GuardedByParkingGate;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id', 'building_id', 'permit_id', 'plate_ciphertext', 'plate_bidx', 'plate_key_version', 'observed_on', 'source', 'created_by',
    ];

    protected $hidden = ['plate_ciphertext', 'plate_bidx'];

    protected function casts(): array
    {
        return [
            'plate_ciphertext' => Bytea::class,
            'plate_bidx' => Bytea::class,
            'plate_key_version' => 'integer',
            'observed_on' => 'immutable_date',
            'source' => ObservationSource::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Permit, $this> */
    public function permit(): BelongsTo
    {
        return $this->belongsTo(Permit::class);
    }
}

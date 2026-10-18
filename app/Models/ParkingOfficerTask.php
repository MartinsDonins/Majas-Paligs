<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OfficerTaskKind;
use App\Enums\OfficerTaskStatus;
use App\Gdpr\GuardedByParkingGate;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parking officer queue (PARK-001.3): permit without vehicle, vehicle conflict (plate active in another apartment),
 * vehicle to verify (declaration / plate change). Ids only — never a plate. parking_officer / building_admin only.
 *
 * @property int $id
 * @property int $building_id
 * @property OfficerTaskKind $kind
 * @property int|null $permit_id
 * @property string|null $vehicle_id
 * @property OfficerTaskStatus $status
 * @property CarbonImmutable $created_at
 * @property int|null $done_by
 * @property CarbonImmutable|null $done_at
 * @property-read Permit|null $permit
 * @property-read Vehicle|null $vehicle
 */
class ParkingOfficerTask extends Model
{
    use BelongsToBuilding;
    use GuardedByParkingGate;

    public const UPDATED_AT = null;

    protected $fillable = ['building_id', 'kind', 'permit_id', 'vehicle_id', 'status', 'done_by', 'done_at'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'kind' => OfficerTaskKind::class,
            'status' => OfficerTaskStatus::class,
            'done_at' => 'immutable_datetime',
            'permit_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', OfficerTaskStatus::Open->value);
    }

    /** @return BelongsTo<Permit, $this> */
    public function permit(): BelongsTo
    {
        return $this->belongsTo(Permit::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}

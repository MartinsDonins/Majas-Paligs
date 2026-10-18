<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PermitStatus;
use App\Enums\PermitStatusReason;
use App\Enums\PermitType;
use App\Gdpr\GuardedByParkingGate;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parking permit (PARK-001): main (plate-bound while active — DB CHECK) or guest (never plate-bound). Overlapping
 * active permits with the same number and type are rejected by an EXCLUDE constraint; the vehicle FK is composite
 * (building, apartment, vehicle) so a permit never points at another apartment's car. A plate change replaces the
 * permit (replaced_by_permit_id keeps the history).
 *
 * @property int $id
 * @property int $building_id
 * @property int $apartment_id
 * @property string $number
 * @property PermitType $type
 * @property string|null $vehicle_id
 * @property string|null $issued_by
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property PermitStatus $status
 * @property PermitStatusReason|null $status_reason
 * @property int|null $replaced_by_permit_id
 * @property string|null $note_private
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Apartment $apartment
 * @property-read Vehicle|null $vehicle
 */
class Permit extends Model
{
    use BelongsToBuilding;
    use GuardedByParkingGate;

    protected $fillable = [
        'building_id', 'apartment_id', 'number', 'type', 'vehicle_id', 'issued_by', 'valid_from', 'valid_to', 'status',
        'status_reason', 'replaced_by_permit_id', 'note_private',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'type' => PermitType::class,
            'status' => PermitStatus::class,
            'status_reason' => PermitStatusReason::class,
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'replaced_by_permit_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PermitStatus::Active->value);
    }

    /** @return BelongsTo<Apartment, $this> */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Permit, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_permit_id');
    }

    /**
     * "Beidzas drīz" — active and ending within 30 days.
     */
    public function expiresSoon(?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::now('Europe/Riga')->startOfDay();

        return $this->status === PermitStatus::Active && $this->valid_to !== null
            && ! $this->valid_to->lessThan($today)
            && $this->valid_to->lessThanOrEqualTo($today->addDays(30));
    }

    /**
     * Display state for chips: active | expiring | expired | returned | lost | revoked.
     */
    public function displayState(?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::now('Europe/Riga')->startOfDay();

        if ($this->status === PermitStatus::Active && $this->valid_to !== null && $this->valid_to->lessThan($today)) {
            return 'expired';
        }

        if ($this->expiresSoon($today)) {
            return 'expiring';
        }

        return $this->status->value;
    }
}

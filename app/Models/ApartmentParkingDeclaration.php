<?php

declare(strict_types=1);

namespace App\Models;

use App\Gdpr\GuardedByParkingGate;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apartment-level parking declaration (PARK-006): "mums nav auto" (has_no_vehicle = true) or "our cars are
 * declared" (false). One row per apartment; a no-car declaration counts towards completeness for 12 months.
 *
 * @property int $id
 * @property int $building_id
 * @property int $apartment_id
 * @property CarbonImmutable $declared_at
 * @property bool $has_no_vehicle
 * @property int|null $declared_by_user_id
 * @property int|null $confirmed_by
 */
class ApartmentParkingDeclaration extends Model
{
    use BelongsToBuilding;
    use GuardedByParkingGate;

    protected $fillable = ['building_id', 'apartment_id', 'declared_at', 'has_no_vehicle', 'declared_by_user_id', 'confirmed_by'];

    protected function casts(): array
    {
        return ['declared_at' => 'immutable_datetime', 'has_no_vehicle' => 'boolean'];
    }

    /** @return BelongsTo<Apartment, $this> */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }
}

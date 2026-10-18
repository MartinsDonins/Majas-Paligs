<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use App\Enums\SightingStatus;
use App\Gdpr\GuardedByParkingGate;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unknown-car report (PARK-003). Visible to the reporter (time, lot, neutral status), the parking officer and the
 * building admin only. `matched_registered` and the repeat flag are officer/admin-only — never in a reporter
 * payload. Photo on the private disk, re-encoded without EXIF, deleted after 30 days; record after 12 months.
 *
 * @property string $id
 * @property int $building_id
 * @property int $parking_lot_id
 * @property string $plate_ciphertext
 * @property string $plate_bidx
 * @property int $plate_key_version
 * @property string $country
 * @property CarbonImmutable $seen_at
 * @property int|null $reporter_user_id
 * @property int $reporter_apartment_id
 * @property bool $matched_registered
 * @property string|null $photo_path
 * @property int|null $photo_bytes
 * @property CarbonImmutable|null $photo_deleted_at
 * @property SightingStatus $status
 * @property int|null $handled_by
 * @property CarbonImmutable|null $handled_at
 * @property CarbonImmutable $created_at
 * @property-read ParkingLot $lot
 */
class Sighting extends Model
{
    use BelongsToBuilding;
    use GuardedByParkingGate;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id', 'building_id', 'parking_lot_id', 'plate_ciphertext', 'plate_bidx', 'plate_key_version', 'country', 'seen_at',
        'reporter_user_id', 'reporter_apartment_id', 'matched_registered', 'photo_path', 'photo_bytes', 'photo_deleted_at',
        'status', 'handled_by', 'handled_at',
    ];

    protected $hidden = ['plate_ciphertext', 'plate_bidx', 'matched_registered', 'photo_path'];

    protected $attributes = ['status' => 'open', 'country' => 'LV', 'matched_registered' => false];

    protected function casts(): array
    {
        return [
            'plate_ciphertext' => Bytea::class,
            'plate_bidx' => Bytea::class,
            'plate_key_version' => 'integer',
            'seen_at' => 'immutable_datetime',
            'matched_registered' => 'boolean',
            'photo_bytes' => 'integer',
            'photo_deleted_at' => 'immutable_datetime',
            'status' => SightingStatus::class,
            'handled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ParkingLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(ParkingLot::class, 'parking_lot_id');
    }

    public function hasPhoto(): bool
    {
        return $this->photo_path !== null && $this->photo_deleted_at === null;
    }

    /**
     * What the REPORTER sees: "submitted" for open/disputed (never the officer's working state), else the outcome.
     */
    public function reporterState(): string
    {
        return in_array($this->status, [SightingStatus::Dismissed, SightingStatus::Resolved], true) ? $this->status->value : 'submitted';
    }
}

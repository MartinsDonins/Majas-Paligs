<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ParkingExportFormat;
use App\Enums\ParkingExportKind;
use App\Gdpr\GuardedByParkingGate;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Parking export request (PARK-005): PE-YYYY-NNNN, four eyes (DB CHECK approved_by <> requested_by), generated on
 * demand through a 10-min signed URL and NEVER stored — this row is the metadata only.
 *
 * @property string $id
 * @property int $building_id
 * @property string $number
 * @property ParkingExportKind $kind
 * @property ParkingExportFormat $format
 * @property int|null $requested_by
 * @property string|null $requested_role
 * @property CarbonImmutable $requested_at
 * @property int|null $approved_by
 * @property string|null $approved_role
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $generated_at
 * @property int|null $row_count
 * @property bool $include_apartments
 * @property CarbonImmutable $expires_at
 */
class ParkingExport extends Model
{
    use BelongsToBuilding;
    use GuardedByParkingGate;
    use HasUlids;

    protected $fillable = [
        'building_id', 'number', 'kind', 'format', 'requested_by', 'requested_role', 'requested_at', 'approved_by',
        'approved_role', 'approved_at', 'generated_at', 'row_count', 'expires_at', 'include_apartments',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ParkingExportKind::class,
            'format' => ParkingExportFormat::class,
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'row_count' => 'integer',
            'include_apartments' => 'boolean',
        ];
    }

    public function isApproved(): bool
    {
        return $this->approved_by !== null && $this->approved_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

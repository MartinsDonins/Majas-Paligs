<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportStatus;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Structural/aggregate house export (MVP-SPEC A.8, ADMIN-004) — no personal data.
 *
 * @property string $id
 * @property int $building_id
 * @property int|null $requested_by
 * @property ExportStatus $status
 * @property string|null $path
 * @property CarbonImmutable|null $expires_at
 */
class BuildingExport extends Model
{
    use BelongsToBuilding;
    use HasUlids;

    protected $fillable = ['building_id', 'requested_by', 'status', 'path', 'expires_at'];

    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'expires_at' => 'immutable_datetime',
        ];
    }
}

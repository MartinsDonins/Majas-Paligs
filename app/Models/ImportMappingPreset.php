<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use App\Enums\ImportKind;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A remembered column mapping per building + kind + header signature (ROUND2-SPEC §4.2). Headers are not personal
 * data; the same sheet layout uploaded again gets its mapping back automatically. Composite primary key — written
 * through ColumnMapper::remember() (upsert), read through the tenant-scoped query.
 *
 * @property int $building_id
 * @property ImportKind $kind
 * @property string $header_signature
 * @property array<string, string> $mapping
 * @property int|null $updated_by
 * @property CarbonImmutable $updated_at
 */
class ImportMappingPreset extends Model
{
    use BelongsToBuilding;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'header_signature';

    protected $keyType = 'string';

    protected $fillable = ['building_id', 'kind', 'header_signature', 'mapping', 'updated_by', 'updated_at'];

    protected function casts(): array
    {
        return [
            'kind' => ImportKind::class,
            'header_signature' => Bytea::class,
            'mapping' => 'array',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

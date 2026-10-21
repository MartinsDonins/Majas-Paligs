<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Remembered column mapping per sheet layout (ROUND2-SPEC §4.2): PK (building_id, kind, header_signature). Headers are
 * not personal data; the preset re-applies when the same layout is uploaded again. Written via ImportRunService.
 *
 * @property int $building_id
 * @property string $kind
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
            'header_signature' => Bytea::class,
            'mapping' => 'array',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

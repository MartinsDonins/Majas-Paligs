<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PgArray;
use App\Enums\ManagerReportKind;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * "Pieteikums pārvaldniekam" (HEAT-005): gap-free number MP-YYYY-NNNN, scope section_ids (NOT NULL, validated by
 * SectionScope + DB trigger), sharing flags as at generation and the snapshot = exactly what was exported.
 * Number, scope, sharing and snapshot are frozen by a DB trigger; only the sent/response log changes.
 *
 * @property string $id
 * @property int $building_id
 * @property string $number
 * @property ManagerReportKind $kind
 * @property list<int> $section_ids
 * @property array<string, mixed> $params
 * @property array{heating_floors?: bool, apartment_numbers?: bool} $sharing
 * @property array<string, mixed> $snapshot
 * @property int|null $generated_by
 * @property CarbonImmutable $generated_at
 * @property CarbonImmutable|null $sent_at
 * @property string|null $manager_ref
 * @property array<string, mixed>|null $response
 */
class ManagerReport extends Model
{
    use BelongsToBuilding;
    use HasUlids;

    protected $fillable = [
        'building_id', 'number', 'kind', 'section_ids', 'params', 'sharing', 'snapshot', 'generated_by', 'generated_at',
        'sent_at', 'manager_ref', 'response',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ManagerReportKind::class,
            'section_ids' => PgArray::class.':int',
            'params' => 'array',
            'sharing' => 'array',
            'snapshot' => 'array',
            'response' => 'array',
            'generated_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}

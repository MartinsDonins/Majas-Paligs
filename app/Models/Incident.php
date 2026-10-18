<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentKind;
use App\Enums\IncidentOrigin;
use App\Enums\IncidentStatus;
use App\Gdpr\CoreGate;
use App\Tenancy\BelongsToBuilding;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * Outage incident (HEAT-004, MVP-SPEC B.1/B.2a). ULID id (never a sequential id to residents). Resident incidents
 * are de-duplicated by (building, kind, scope_key) while open (partial UNIQUE); notices (origin=notice) are written
 * by an elder/admin and cover a riser, column, section or the whole building (optionally a floor range).
 * `is_open` is a generated column. Model guard: resident-origin incidents need gate L-core.
 *
 * @property string $id
 * @property int $building_id
 * @property IncidentKind $kind
 * @property IncidentOrigin $origin
 * @property int|null $section_id
 * @property int|null $riser_id
 * @property int|null $column_position
 * @property string|null $floors
 * @property string $scope_key
 * @property IncidentStatus $status
 * @property bool $is_open
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $expected_end_at
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $manager_ref
 * @property int|null $opened_by
 * @property CarbonImmutable $created_at
 * @property int|null $confirmations_count
 * @property-read Riser|null $riser
 * @property-read Section|null $section
 */
class Incident extends Model
{
    use BelongsToBuilding;
    use HasTranslations;
    use HasUlids;

    /** @var list<string> */
    public array $translatable = ['reason', 'manager_reply'];

    protected $fillable = [
        'building_id', 'kind', 'origin', 'section_id', 'riser_id', 'column_position', 'floors', 'scope_key', 'status',
        'started_at', 'expected_end_at', 'resolved_at', 'reason', 'manager_ref', 'manager_reply', 'opened_by',
    ];

    protected $attributes = [
        'status' => 'reported',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $incident): void {
            if ($incident->origin === IncidentOrigin::Resident) {
                CoreGate::assertOpen(CurrentBuilding::get());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => IncidentKind::class,
            'origin' => IncidentOrigin::class,
            'status' => IncidentStatus::class,
            'is_open' => 'boolean',
            'column_position' => 'integer',
            'section_id' => 'integer',
            'riser_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'expected_end_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_open', true);
    }

    /** @return HasMany<IncidentConfirmation, $this> */
    public function confirmations(): HasMany
    {
        return $this->hasMany(IncidentConfirmation::class);
    }

    /** @return BelongsTo<Riser, $this> */
    public function riser(): BelongsTo
    {
        return $this->belongsTo(Riser::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * Floor range of a notice as [from, to] (inclusive), or null for all floors.
     *
     * @return array{0: int, 1: int}|null
     */
    public function floorRange(): ?array
    {
        if ($this->floors === null || preg_match('/^\[(\d+),(\d+)\)$/', (string) $this->floors, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2] - 1];
    }

    /**
     * int4range literal for an inclusive floor range.
     */
    public static function floorsLiteral(?int $from, ?int $to): ?string
    {
        if ($from === null || $to === null) {
            return null;
        }

        return '['.min($from, $to).','.(max($from, $to) + 1).')';
    }
}

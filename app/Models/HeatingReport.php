<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HeatingStatus;
use App\Enums\HeatingTriage;
use App\Enums\ReportSource;
use App\Gdpr\CoreGate;
use App\Tenancy\BelongsToBuilding;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One room's radiator state reported by (or on behalf of) an apartment (HEAT-002). House data. The latest active
 * row per (room, round) is unique (partial index); a new report supersedes the previous one. note_private is
 * visible to building_admin / the section's elder only and never leaves the house (no manager export).
 * Model guard: no report while gate L-core is closed — from any path.
 *
 * @property int $id
 * @property int $building_id
 * @property int $apartment_id
 * @property int $room_id
 * @property int|null $survey_round_id
 * @property string $submission_id
 * @property HeatingStatus $status
 * @property string|null $temperature_c
 * @property HeatingTriage|null $triage
 * @property string|null $note_private
 * @property ReportSource $source
 * @property int|null $reported_by_user_id
 * @property CarbonImmutable $reported_at
 * @property CarbonImmutable $valid_until
 * @property CarbonImmutable|null $superseded_at
 * @property string|null $superseded_by
 * @property CarbonImmutable|null $hidden_at
 * @property int|null $hidden_by
 * @property string|null $hidden_reason
 * @property-read Apartment $apartment
 * @property-read Room $room
 */
class HeatingReport extends Model
{
    use BelongsToBuilding;

    protected $fillable = [
        'building_id', 'apartment_id', 'room_id', 'survey_round_id', 'submission_id', 'status', 'temperature_c',
        'triage', 'note_private', 'source', 'reported_by_user_id', 'reported_at', 'valid_until', 'superseded_at',
        'superseded_by', 'hidden_at', 'hidden_by', 'hidden_reason',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $report): void {
            CoreGate::assertOpen(CurrentBuilding::get());
        });
    }

    protected function casts(): array
    {
        return [
            'status' => HeatingStatus::class,
            'triage' => HeatingTriage::class,
            'source' => ReportSource::class,
            'reported_at' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'hidden_at' => 'immutable_datetime',
        ];
    }

    /**
     * Not superseded and not hidden.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('superseded_at')->whereNull('hidden_at');
    }

    /**
     * Active and still inside the rolling validity window.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->active()->where('valid_until', '>', now());
    }

    /** @return BelongsTo<Apartment, $this> */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<SurveyRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(SurveyRound::class, 'survey_round_id');
    }
}

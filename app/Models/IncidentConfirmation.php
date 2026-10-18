<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportSource;
use App\Gdpr\CoreGate;
use App\Tenancy\BelongsToBuilding;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Man arī" — one confirmation per (incident, apartment), idempotent (UNIQUE). House data.
 * Model guard: gate L-core must be open.
 *
 * @property int $id
 * @property int $building_id
 * @property string $incident_id
 * @property int $apartment_id
 * @property int|null $user_id
 * @property ReportSource $source
 * @property CarbonImmutable $created_at
 */
class IncidentConfirmation extends Model
{
    use BelongsToBuilding;

    public const UPDATED_AT = null;

    protected $fillable = ['building_id', 'incident_id', 'apartment_id', 'user_id', 'source'];

    protected static function booted(): void
    {
        static::creating(static function (self $confirmation): void {
            CoreGate::assertOpen(CurrentBuilding::get());
        });
    }

    protected function casts(): array
    {
        return [
            'source' => ReportSource::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}

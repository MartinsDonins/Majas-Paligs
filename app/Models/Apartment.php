<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PgArray;
use App\Enums\Side;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $building_id
 * @property int $section_id
 * @property int $number
 * @property int $floor
 * @property int $position
 * @property Side|null $side
 * @property int|null $rooms_count
 * @property string|null $area_m2
 * @property int|null $series_apartment_type_id
 * @property bool $is_override
 * @property string|null $override_note
 * @property list<int> $retired_numbers
 * @property CarbonImmutable|null $archived_at
 * @property-read Section $section
 */
class Apartment extends Model
{
    use BelongsToBuilding;

    protected $fillable = [
        'building_id', 'section_id', 'number', 'floor', 'position', 'side', 'rooms_count', 'area_m2',
        'series_apartment_type_id', 'is_override', 'override_note', 'retired_numbers', 'archived_at',
    ];

    protected $attributes = [
        'retired_numbers' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'floor' => 'integer',
            'position' => 'integer',
            'rooms_count' => 'integer',
            'side' => Side::class,
            'is_override' => 'boolean',
            'retired_numbers' => PgArray::class.':int',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<SeriesApartmentType, $this> */
    public function seriesType(): BelongsTo
    {
        return $this->belongsTo(SeriesApartmentType::class, 'series_apartment_type_id');
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('sort');
    }

    /** @return HasMany<ApartmentCell, $this> */
    public function cells(): HasMany
    {
        return $this->hasMany(ApartmentCell::class);
    }

    /** @return HasMany<RiserLink, $this> */
    public function riserLinks(): HasMany
    {
        return $this->hasMany(RiserLink::class);
    }

    /** @return HasMany<ApartmentMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ApartmentMembership::class);
    }

    /** @return HasMany<ApartmentInvite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(ApartmentInvite::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}

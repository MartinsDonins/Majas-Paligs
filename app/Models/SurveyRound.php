<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PgArray;
use App\Enums\SurveyRoundStatus;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * A heating survey round (comparable snapshot, HEAT-003). section_ids null = whole building (building_admin
 * only); otherwise validated by SectionScope and by the assert_section_ids_in_building() trigger.
 *
 * @property int $id
 * @property int $building_id
 * @property string $kind
 * @property list<int>|null $section_ids
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property SurveyRoundStatus $status
 * @property int|null $created_by
 */
class SurveyRound extends Model
{
    use BelongsToBuilding;
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['title'];

    protected $fillable = ['building_id', 'title', 'kind', 'section_ids', 'starts_at', 'ends_at', 'status', 'created_by'];

    protected $attributes = [
        'kind' => 'heating_check',
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => SurveyRoundStatus::class,
        ];
    }

    /**
     * section_ids: NULL stays NULL (whole building), otherwise a bigint[] literal.
     *
     * @return list<int>|null
     */
    public function getSectionIdsAttribute(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        /** @var list<int> $ids */
        $ids = (new PgArray('int'))->get($this, 'section_ids', $value, []);

        return $ids;
    }

    /**
     * @param  list<int>|null  $value
     */
    public function setSectionIdsAttribute(?array $value): void
    {
        $this->attributes['section_ids'] = $value === null ? null : PgArray::literal($value, 'int');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', SurveyRoundStatus::Open->value);
    }

    /**
     * Open round covering a section right now (newest first); whole-building rounds cover every section.
     */
    public static function openFor(int $sectionId): ?self
    {
        return self::query()->open()
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->where(fn (Builder $q) => $q->whereNull('section_ids')->orWhereRaw('? = ANY (section_ids)', [$sectionId]))
            ->orderByDesc('starts_at')
            ->first();
    }

    public function covers(int $sectionId): bool
    {
        return $this->section_ids === null || in_array($sectionId, $this->section_ids, true);
    }

    public function roundKey(): string
    {
        return 'round:'.$this->id;
    }

    /** @return HasMany<HeatingReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(HeatingReport::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The house's own copy of a platform master (ROUND2-SPEC §3.3, GOV-011): LV + RU bodies with the editable blocks
 * the admin changed, DPIA-lite answers, and meta {requested_scope, covers_modules, snapshot}. Written only through
 * DocumentService (lint before finalise; frozen once it left draft — DB trigger).
 *
 * @property string $id
 * @property int $building_id
 * @property DocumentKind $kind
 * @property int $template_id
 * @property int $template_version
 * @property int $version
 * @property string $body_lv
 * @property string $body_ru
 * @property array<string, array{answer: ?string, note: ?string}>|null $answers
 * @property array<string, mixed> $meta
 * @property DocumentStatus $status
 * @property int|null $created_by
 * @property int|null $finalized_by
 * @property CarbonImmutable|null $finalized_at
 * @property int|null $sent_by
 * @property CarbonImmutable|null $sent_at
 * @property int|null $recorded_by
 * @property CarbonImmutable|null $recorded_at
 * @property string|null $superseded_by
 * @property CarbonImmutable $created_at
 * @property-read DocumentTemplate $template
 */
class BuildingDocument extends Model
{
    use BelongsToBuilding;
    use HasUlids;

    protected $fillable = [
        'building_id', 'kind', 'template_id', 'template_version', 'version', 'body_lv', 'body_ru', 'answers', 'meta',
        'status', 'created_by', 'finalized_by', 'finalized_at', 'sent_by', 'sent_at', 'recorded_by', 'recorded_at',
        'superseded_by',
    ];

    protected $attributes = [
        'meta' => '{}',
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'status' => DocumentStatus::class,
            'version' => 'integer',
            'template_version' => 'integer',
            'answers' => 'array',
            'meta' => 'array',
            'finalized_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DocumentTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfKind(Builder $query, DocumentKind $kind): Builder
    {
        return $query->where('kind', $kind->value);
    }

    /**
     * The newest version of a kind in the current building (any status but superseded).
     */
    public static function latestOf(DocumentKind $kind): ?self
    {
        return self::query()->ofKind($kind)->where('status', '!=', DocumentStatus::Superseded->value)
            ->orderByDesc('version')->first();
    }

    public function body(string $locale): string
    {
        return $locale === 'ru' ? $this->body_ru : $this->body_lv;
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    /**
     * @return list<string>
     */
    public function requestedScope(): array
    {
        return array_values(array_map('strval', (array) ($this->meta['requested_scope'] ?? [])));
    }

    /**
     * @return list<string>
     */
    public function coversModules(): array
    {
        return array_values(array_map('strval', (array) ($this->meta['covers_modules'] ?? [])));
    }
}

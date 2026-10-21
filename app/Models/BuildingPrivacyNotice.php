<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PgArray;
use App\Services\Documents\DocumentRenderer;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A building privacy notice (house = controller): adopted from a platform template version, or — round 2
 * (ROUND2-SPEC §3.3, D7) — published from the house's own edited document (`document_id`, `body_override` {lv, ru}:
 * filled Markdown, rendered sanitised). The CURRENT notice is the newest published, not withdrawn row. Read by
 * CoreGate / ParkingGate / RosterGate (PLANNING §5).
 *
 * @property int $id
 * @property int $building_id
 * @property int|null $template_version_id
 * @property string|null $document_id
 * @property array{lv: string, ru: string}|null $body_override
 * @property array{name: ?string, contact: ?string} $controller_snapshot
 * @property list<string> $covers_modules
 * @property CarbonImmutable|null $published_at
 * @property int|null $published_by
 * @property CarbonImmutable|null $withdrawn_at
 * @property-read PrivacyNoticeVersion $template
 */
class BuildingPrivacyNotice extends Model
{
    use BelongsToBuilding;

    protected $fillable = ['building_id', 'template_version_id', 'document_id', 'body_override', 'controller_snapshot', 'covers_modules', 'published_at', 'published_by', 'withdrawn_at'];

    protected $attributes = [
        'covers_modules' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'controller_snapshot' => 'array',
            'body_override' => 'array',
            'covers_modules' => PgArray::class,
            'published_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PrivacyNoticeVersion, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PrivacyNoticeVersion::class, 'template_version_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->whereNull('withdrawn_at');
    }

    /**
     * Current notice of the current building.
     */
    public static function current(): ?self
    {
        return self::query()->effective()->orderByDesc('published_at')->orderByDesc('id')->first();
    }

    /**
     * @param  list<string>  $modules
     */
    public function covers(array $modules): bool
    {
        return array_diff($modules, $this->covers_modules) === [];
    }

    /**
     * Plain text of a template-based notice, or the filled Markdown of a document-based one.
     */
    public function render(string $locale): string
    {
        if ($this->body_override !== null) {
            return (string) ($this->body_override[$locale === 'ru' ? 'ru' : 'lv'] ?? '');
        }

        return $this->template->render($locale, $this->controller_snapshot);
    }

    /**
     * Safe HTML for the public notice page: a document-based notice is sanitised Markdown (html_input: strip), a
     * template-based one is escaped text with its paragraphs kept.
     */
    public function renderHtml(string $locale): string
    {
        if ($this->body_override !== null) {
            return app(DocumentRenderer::class)->html($this->render($locale));
        }

        return collect(preg_split('/
{2,}/', $this->render($locale)) ?: [])
            ->map(static fn (string $p): string => '<p>'.nl2br(e(trim($p))).'</p>')
            ->implode('');
    }
}

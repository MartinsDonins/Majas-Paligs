<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PgArray;
use App\Enums\DocumentKind;
use App\Enums\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform master of a house document (ROUND2-SPEC §3.3–3.4, GOV-011): one row per kind × version × locale, Markdown
 * with `<!-- req:key -->…<!-- /req -->` (locked) and `<!-- edit:key -->…<!-- /edit -->` (editable) blocks. Seeded from
 * database/templates/{kind}.{locale}.md; a published version is immutable (DB trigger) — a change is a new version.
 * No building_id: platform table, written only by platform_admin (DocumentTemplatePolicy) and the seeder.
 *
 * @property int $id
 * @property DocumentKind $kind
 * @property int $version
 * @property Locale $locale
 * @property string $title
 * @property string $body
 * @property list<string> $required_markers
 * @property list<string> $placeholders
 * @property list<string> $covers_modules
 * @property CarbonImmutable|null $published_at
 * @property int|null $created_by
 */
class DocumentTemplate extends Model
{
    protected $fillable = ['kind', 'version', 'locale', 'title', 'body', 'required_markers', 'placeholders', 'covers_modules', 'published_at', 'created_by'];

    protected $attributes = [
        'required_markers' => '{}',
        'placeholders' => '{}',
        'covers_modules' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'locale' => Locale::class,
            'version' => 'integer',
            'required_markers' => PgArray::class,
            'placeholders' => PgArray::class,
            'covers_modules' => PgArray::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    /**
     * The newest published version of a kind in a locale.
     */
    public static function latest(DocumentKind $kind, Locale $locale): ?self
    {
        return self::query()->published()->where('kind', $kind->value)->where('locale', $locale->value)
            ->orderByDesc('version')->first();
    }

    /**
     * The other-language row of the same kind + version.
     */
    public function sibling(Locale $locale): ?self
    {
        return self::query()->where('kind', $this->kind->value)->where('version', $this->version)
            ->where('locale', $locale->value)->first();
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * The master a house document is prepared from: the newest published LV version and its published RU sibling of
     * the same version (DocumentService::prepare). Null when either language is missing — then a house cannot prepare
     * that document in the opening wizard.
     *
     * @return array{lv: self, ru: self}|null
     */
    public static function publishedPair(DocumentKind $kind): ?array
    {
        $lv = self::latest($kind, Locale::Lv);
        $ru = $lv?->sibling(Locale::Ru);

        return $lv !== null && $ru !== null && $ru->isPublished() ? ['lv' => $lv, 'ru' => $ru] : null;
    }
}

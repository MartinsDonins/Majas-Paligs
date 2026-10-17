<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PgArray;
use App\Enums\PrivacyNoticeKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Platform privacy notice texts (MVP-SPEC A.4): `platform_account` = CoreDigify's own notice as controller of
 * accounts; `building_template` = text a house adopts (placeholders {controller.name}, {controller.contact}).
 *
 * @property int $id
 * @property PrivacyNoticeKind $kind
 * @property string $version
 * @property list<string> $covers_modules
 * @property CarbonImmutable|null $published_at
 */
class PrivacyNoticeVersion extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['body'];

    protected $fillable = ['kind', 'version', 'body', 'covers_modules', 'published_at'];

    protected function casts(): array
    {
        return [
            'kind' => PrivacyNoticeKind::class,
            'covers_modules' => PgArray::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * The newest published version of a kind.
     */
    public static function current(PrivacyNoticeKind $kind): ?self
    {
        return self::query()
            ->where('kind', $kind->value)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
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
     * Render the body for a locale with controller placeholders filled in.
     *
     * @param  array{name?: ?string, contact?: ?string}  $controller
     */
    public function render(string $locale, array $controller = []): string
    {
        $body = (string) $this->getTranslation('body', $locale);

        return strtr($body, [
            '{controller.name}' => (string) ($controller['name'] ?? ''),
            '{controller.contact}' => (string) ($controller['contact'] ?? ''),
        ]);
    }
}

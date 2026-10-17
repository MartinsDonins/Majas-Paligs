<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PgArray;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A building privacy notice adopted from a template (house = controller). The CURRENT notice is the newest
 * published, not withdrawn row. Read by CoreGate / ParkingGate (PLANNING §5).
 *
 * @property int $id
 * @property int $building_id
 * @property int $template_version_id
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

    protected $fillable = ['building_id', 'template_version_id', 'controller_snapshot', 'covers_modules', 'published_at', 'published_by', 'withdrawn_at'];

    protected $attributes = [
        'covers_modules' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'controller_snapshot' => 'array',
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

    public function render(string $locale): string
    {
        return $this->template->render($locale, $this->controller_snapshot);
    }
}

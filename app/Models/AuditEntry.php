<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PgArray;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only, hash-chained audit row (PLANNING §5 A09). Written only through App\Audit\AuditLogger;
 * the DB trigger assigns id, created_at, prev_hash and hash. Updates and deletes are refused here and in SQL.
 *
 * Not a BelongsToBuilding model: platform-chain rows have no building. Every read path filters explicitly
 * (building log: forBuilding(); "who viewed my data": UserScopedQuery::auditAboutMe()).
 *
 * @property int $id
 * @property int $chain_key
 * @property int|null $building_id
 * @property int|null $actor_user_id
 * @property string|null $actor_role
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property list<int> $subject_user_ids
 * @property string|null $reason_code
 * @property string|null $reason_text
 * @property array<string, mixed> $data
 * @property CarbonImmutable $created_at
 */
class AuditEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'subject_user_ids' => PgArray::class.':int',
            'data' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('audit_entries is append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('audit_entries is append-only.');
        });
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForBuilding(Builder $query, int $buildingId): Builder
    {
        return $query->where('chain_key', $buildingId)->where('building_id', $buildingId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePlatformChain(Builder $query): Builder
    {
        return $query->where('chain_key', 0);
    }
}

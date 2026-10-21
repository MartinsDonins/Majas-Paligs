<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of the CSV import pipeline (DB-003, ROUND2-SPEC §4.2): upload → mapping → validation → dry-run → commit,
 * resumable for 24 h. The CSV exists only as ImportVault ciphertext (`payload_ciphertext`, nulled on commit /
 * discard / expiry). `summary` and `committed_counts` hold counts per validation code — never cell values.
 *
 * @property string $id
 * @property int $building_id
 * @property string $kind
 * @property string $status
 * @property int|null $created_by
 * @property string $file_sha256
 * @property string $encoding
 * @property string $delimiter
 * @property bool $has_header
 * @property int $row_count
 * @property array<string, string> $mapping
 * @property array<string, mixed> $options
 * @property string|null $payload_ciphertext
 * @property array<string, mixed> $summary
 * @property array<string, int>|null $committed_counts
 * @property string|null $error_code
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ImportRun extends Model
{
    use BelongsToBuilding;
    use HasUlids;

    public const UPLOADED = 'uploaded';

    public const MAPPED = 'mapped';

    public const VALIDATED = 'validated';

    public const COMMITTING = 'committing';

    public const COMMITTED = 'committed';

    public const FAILED = 'failed';

    public const DISCARDED = 'discarded';

    public const EXPIRED = 'expired';

    /** Statuses in which the run can still be worked on (the payload exists). */
    public const OPEN = [self::UPLOADED, self::MAPPED, self::VALIDATED];

    protected $fillable = [
        'id', 'building_id', 'kind', 'status', 'created_by', 'file_sha256', 'encoding', 'delimiter', 'has_header',
        'row_count', 'mapping', 'options', 'payload_ciphertext', 'summary', 'committed_counts', 'error_code', 'expires_at',
    ];

    protected $hidden = ['payload_ciphertext', 'file_sha256'];

    protected $attributes = [
        'status' => self::UPLOADED,
        'mapping' => '{}',
        'options' => '{}',
        'summary' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'file_sha256' => Bytea::class,
            'payload_ciphertext' => Bytea::class,
            'has_header' => 'boolean',
            'row_count' => 'integer',
            'mapping' => 'array',
            'options' => 'array',
            'summary' => 'array',
            'committed_counts' => 'array',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true) && $this->expires_at->isFuture() && $this->payload_ciphertext !== null;
    }

    public function step(): int
    {
        return match ($this->status) {
            self::UPLOADED => 2,
            self::MAPPED => 3,
            self::VALIDATED => 4,
            default => 5,
        };
    }

    /**
     * When the last dry-run finished (summary.dry_run_at), or null.
     */
    public function dryRunAt(): ?CarbonImmutable
    {
        $at = $this->summary['dry_run_at'] ?? null;

        return is_string($at) ? CarbonImmutable::parse($at) : null;
    }
}

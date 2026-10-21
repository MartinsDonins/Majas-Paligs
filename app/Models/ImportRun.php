<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\Bytea;
use App\Enums\ImportKind;
use App\Enums\ImportStatus;
use App\Services\Import\ImportRunService;
use App\Tenancy\BelongsToBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One CSV import run (DB-003, ROUND2-SPEC §4.2). The file exists only as ImportVault ciphertext
 * (`payload_ciphertext`, nulled on commit / discard / expiry); `summary` and `committed_counts` hold counts per
 * validation code, never cell values. Model guard (D6): a non-terminal run cannot be saved while the gate of its kind
 * is closed (permits: parking L1, roster: L-R) — closing / discarding a run is always allowed.
 *
 * @property string $id
 * @property int $building_id
 * @property ImportKind $kind
 * @property ImportStatus $status
 * @property int|null $created_by
 * @property string $file_sha256
 * @property string $encoding
 * @property string $delimiter
 * @property bool $has_header
 * @property int $row_count
 * @property array<int, string>|null $mapping
 * @property array<string, mixed>|null $options
 * @property string|null $payload_ciphertext
 * @property array<string, mixed>|null $summary
 * @property array<string, mixed>|null $committed_counts
 * @property string|null $error_code
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ImportRun extends Model
{
    use BelongsToBuilding;
    use HasUlids;

    protected $fillable = [
        'id', 'building_id', 'kind', 'status', 'created_by', 'file_sha256', 'encoding', 'delimiter', 'has_header',
        'row_count', 'mapping', 'options', 'payload_ciphertext', 'summary', 'committed_counts', 'error_code', 'expires_at',
    ];

    protected $hidden = ['payload_ciphertext', 'file_sha256'];

    protected $attributes = ['status' => 'uploaded'];

    protected function casts(): array
    {
        return [
            'kind' => ImportKind::class,
            'status' => ImportStatus::class,
            'file_sha256' => Bytea::class,
            'payload_ciphertext' => Bytea::class,
            'has_header' => 'boolean',
            'row_count' => 'integer',
            'mapping' => 'array',
            'options' => 'array',
            'summary' => 'array',
            'committed_counts' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (self $run): void {
            if (! $run->status->isTerminal()) {
                app(ImportRunService::class)->handler($run->kind)->assertGateOpen();
            }
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Mode chosen at mapping: create_only (default) or create_or_update.
     */
    public function mode(): string
    {
        return ($this->options['mode'] ?? 'create_only') === 'create_or_update' ? 'create_or_update' : 'create_only';
    }
}

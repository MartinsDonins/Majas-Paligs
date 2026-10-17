<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Self-service GDPR export of a user (all buildings). Global (user-scoped), not a tenant model.
 *
 * @property string $id
 * @property int $user_id
 * @property ExportStatus $status
 * @property string|null $path
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 */
class DataExport extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'status', 'path', 'expires_at'];

    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

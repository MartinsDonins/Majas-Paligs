<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One reported exception fingerprint (ErrorEventRecorder): class, file:line, a scrubbed one-line message, how often
 * and when. No request data, no user, no IP. Read by the MCP `app_status` tool; pruned after 30 days.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $exception_class
 * @property string $location
 * @property string $message
 * @property string $origin
 * @property int $count
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 */
class ErrorEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['fingerprint', 'exception_class', 'location', 'message', 'origin', 'count', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}

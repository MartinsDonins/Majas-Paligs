<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Parking officer task status.
 */
enum OfficerTaskStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Done = 'done';
    case Dismissed = 'dismissed';

    protected static function labelPrefix(): string
    {
        return 'officer_task_status';
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    protected static function labelPrefix(): string
    {
        return 'dispute_status';
    }
}

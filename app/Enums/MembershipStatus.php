<?php

declare(strict_types=1);

namespace App\Enums;

enum MembershipStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Ended = 'ended';

    protected static function labelPrefix(): string
    {
        return 'membership_status';
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sighting status (MVP-SPEC C.1). Reporters see only open (as "nodots") / dismissed / resolved.
 */
enum SightingStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Disputed = 'disputed';
    case Dismissed = 'dismissed';
    case Resolved = 'resolved';

    protected static function labelPrefix(): string
    {
        return 'sighting_status';
    }
}

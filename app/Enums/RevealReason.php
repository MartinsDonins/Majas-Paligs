<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Reason for revealing plate ↔ apartment (MVP-SPEC C.5); "other" requires a text.
 */
enum RevealReason: string
{
    use HasLabel;

    case PoliceRequest = 'police_request';
    case BlockingExit = 'blocking_exit';
    case Damage = 'damage';
    case Other = 'other';

    protected static function labelPrefix(): string
    {
        return 'reveal_reason';
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Parking permit status (MVP-SPEC C.1).
 */
enum PermitStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Returned = 'returned';
    case Lost = 'lost';
    case Revoked = 'revoked';
    case Expired = 'expired';

    protected static function labelPrefix(): string
    {
        return 'permit_status';
    }
}

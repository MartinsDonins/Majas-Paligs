<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a guest-permit plate observation came from (ROUND2-SPEC §6.1): the issuer's list import or an officer.
 */
enum ObservationSource: string
{
    use HasLabel;

    case Import = 'import';
    case Officer = 'officer';

    protected static function labelPrefix(): string
    {
        return 'observation_source';
    }
}

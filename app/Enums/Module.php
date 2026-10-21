<?php

declare(strict_types=1);

namespace App\Enums;

enum Module: string
{
    use HasLabel;

    case Account = 'account';
    case Core = 'core';
    case Heating = 'heating';
    case Outages = 'outages';
    case Parking = 'parking';

    /** Resident roster — admin-only contact register of the house (ROUND2-SPEC D1/D2): a DPA scope and notice module. */
    case Roster = 'roster';

    protected static function labelPrefix(): string
    {
        return 'module';
    }
}

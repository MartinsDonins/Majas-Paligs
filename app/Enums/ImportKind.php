<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a CSV import run loads (ROUND2-SPEC §4.2): the resident roster or the permit issuer's list.
 */
enum ImportKind: string
{
    use HasLabel;

    case Roster = 'roster';
    case Permits = 'permits';

    protected static function labelPrefix(): string
    {
        return 'import_kind';
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a roster contact came from (ROUND2-SPEC §5.1): the house's old list (import), an admin or an elder.
 */
enum ContactSource: string
{
    use HasLabel;

    case Import = 'import';
    case Admin = 'admin';
    case Elder = 'elder';

    protected static function labelPrefix(): string
    {
        return 'contact_source';
    }
}

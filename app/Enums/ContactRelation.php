<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Relation of a roster contact to the apartment (ROUND2-SPEC §5.1).
 */
enum ContactRelation: string
{
    use HasLabel;

    case Owner = 'owner';
    case Tenant = 'tenant';
    case Contact = 'contact';

    protected static function labelPrefix(): string
    {
        return 'contact_relation';
    }
}

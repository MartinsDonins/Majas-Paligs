<?php

declare(strict_types=1);

namespace App\Enums;

enum MembershipRole: string
{
    use HasLabel;

    case Owner = 'owner';
    case Tenant = 'tenant';
    case Family = 'family';
    case Contact = 'contact';

    protected static function labelPrefix(): string
    {
        return 'membership_role';
    }
}

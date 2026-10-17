<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationKind: string
{
    use HasLabel;

    case Association = 'association';
    case Kopiba = 'kopiba';
    case Manager = 'manager';
    case Other = 'other';

    protected static function labelPrefix(): string
    {
        return 'organization_kind';
    }
}

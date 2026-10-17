<?php

declare(strict_types=1);

namespace App\Enums;

enum PlatformRole: string
{
    use HasLabel;

    case PlatformAdmin = 'platform_admin';

    protected static function labelPrefix(): string
    {
        return 'platform_role';
    }
}

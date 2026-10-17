<?php

declare(strict_types=1);

namespace App\Enums;

enum Theme: string
{
    use HasLabel;

    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    protected static function labelPrefix(): string
    {
        return 'theme';
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum TextSize: string
{
    use HasLabel;

    case Normal = 'normal';
    case Large = 'large';

    protected static function labelPrefix(): string
    {
        return 'text_size';
    }
}

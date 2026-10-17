<?php

declare(strict_types=1);

namespace App\Enums;

enum Side: string
{
    use HasLabel;

    case Left = 'left';
    case Right = 'right';

    protected static function labelPrefix(): string
    {
        return 'side';
    }
}

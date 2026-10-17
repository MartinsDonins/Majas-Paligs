<?php

declare(strict_types=1);

namespace App\Enums;

enum NumberingDirection: string
{
    use HasLabel;

    case Ltr = 'ltr';
    case Rtl = 'rtl';

    protected static function labelPrefix(): string
    {
        return 'numbering_direction';
    }
}

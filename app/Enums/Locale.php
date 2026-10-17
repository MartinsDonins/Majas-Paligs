<?php

declare(strict_types=1);

namespace App\Enums;

enum Locale: string
{
    use HasLabel;

    case Lv = 'lv';
    case Ru = 'ru';

    protected static function labelPrefix(): string
    {
        return 'locale';
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum SeriesStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Published = 'published';

    protected static function labelPrefix(): string
    {
        return 'series_status';
    }
}

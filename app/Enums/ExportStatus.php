<?php

declare(strict_types=1);

namespace App\Enums;

enum ExportStatus: string
{
    use HasLabel;

    case Queued = 'queued';
    case Ready = 'ready';
    case Expired = 'expired';
    case Failed = 'failed';

    protected static function labelPrefix(): string
    {
        return 'export_status';
    }
}

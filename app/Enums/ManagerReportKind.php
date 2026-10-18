<?php

declare(strict_types=1);

namespace App\Enums;

enum ManagerReportKind: string
{
    use HasLabel;

    case Heating = 'heating';
    case Outage = 'outage';

    protected static function labelPrefix(): string
    {
        return 'manager_report_kind';
    }
}

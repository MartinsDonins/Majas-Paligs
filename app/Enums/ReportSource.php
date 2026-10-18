<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who entered a heating report or outage confirmation: the resident (self) or an elder/volunteer on their behalf
 * (proxy = in person, phone = by phone).
 */
enum ReportSource: string
{
    use HasLabel;

    case Self = 'self';
    case Proxy = 'proxy';
    case Phone = 'phone';

    protected static function labelPrefix(): string
    {
        return 'report_source';
    }
}

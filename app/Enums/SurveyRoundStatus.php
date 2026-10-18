<?php

declare(strict_types=1);

namespace App\Enums;

enum SurveyRoundStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';

    protected static function labelPrefix(): string
    {
        return 'survey_round_status';
    }
}

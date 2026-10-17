<?php

declare(strict_types=1);

namespace App\Enums;

enum RiserConfidence: string
{
    use HasLabel;

    case Hypothesis = 'hypothesis';
    case ResidentConfirmed = 'resident_confirmed';
    case ManagerConfirmed = 'manager_confirmed';
    case DrawingConfirmed = 'drawing_confirmed';

    protected static function labelPrefix(): string
    {
        return 'riser_confidence';
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum IncidentStatus: string
{
    use HasLabel;

    case Reported = 'reported';
    case Confirmed = 'confirmed';
    case ManagerNotified = 'manager_notified';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    protected static function labelPrefix(): string
    {
        return 'incident_status';
    }

    public function isOpen(): bool
    {
        return $this !== self::Resolved && $this !== self::Rejected;
    }
}

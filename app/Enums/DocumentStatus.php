<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * draft (editable) → final (linted, frozen, PDF) → sent (to CoreDigify, DPA only) → recorded (platform entered the
 * signed DPA) / superseded (a newer version replaced it). ROUND2-SPEC §3.3.
 */
enum DocumentStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Final = 'final';
    case Sent = 'sent';
    case Recorded = 'recorded';
    case Superseded = 'superseded';

    protected static function labelPrefix(): string
    {
        return 'document_status';
    }
}

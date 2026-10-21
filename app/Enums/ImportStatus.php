<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Import run lifecycle (ROUND2-SPEC §4.1/§4.2): uploaded → mapped → validated (dry-run) → committing → committed;
 * failed, discarded and expired are terminal and drop the sealed payload.
 */
enum ImportStatus: string
{
    use HasLabel;

    case Uploaded = 'uploaded';
    case Mapped = 'mapped';
    case Validated = 'validated';
    case Committing = 'committing';
    case Committed = 'committed';
    case Failed = 'failed';
    case Discarded = 'discarded';
    case Expired = 'expired';

    protected static function labelPrefix(): string
    {
        return 'import_status';
    }

    /**
     * Terminal states keep no payload.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Committed, self::Failed, self::Discarded, self::Expired], true);
    }
}

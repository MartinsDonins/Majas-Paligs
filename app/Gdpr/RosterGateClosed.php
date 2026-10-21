<?php

declare(strict_types=1);

namespace App\Gdpr;

use RuntimeException;

/**
 * Thrown by the roster model guard (ResidentContact) and the roster services while gate L-R is closed — catches
 * imports, console, seeders and tinker, not only the UI (ROUND2-SPEC §3.1). The message carries condition keys only.
 */
final class RosterGateClosed extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(public readonly array $missing = [])
    {
        parent::__construct('Roster gate L-R is closed (missing: '.implode(', ', $missing).').');
    }
}

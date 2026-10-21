<?php

declare(strict_types=1);

namespace App\Gdpr;

use RuntimeException;

/**
 * Thrown by the roster model guard (GuardedByRosterGate) while gate L-R is closed — from any path (UI, import,
 * console, tinker), not only the policy (ROUND2-SPEC §3.1).
 */
final class RosterGateClosed extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(public readonly array $missing = [])
    {
        parent::__construct('Roster gate L-R is closed: '.implode(', ', $missing).'.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * A valid invite code was presented but the building's core processing gate (L-core) is closed.
 * The resident sees the neutral "Šī māja vēl nav atvērta pieteikšanai" screen (PLANNING §5).
 */
final class HouseClosed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The core processing gate of this building is closed.');
    }
}

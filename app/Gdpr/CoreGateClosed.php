<?php

declare(strict_types=1);

namespace App\Gdpr;

use RuntimeException;

/**
 * Thrown by model guards (memberships, heating reports, incident confirmations) below gate L-core.
 */
final class CoreGateClosed extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(public readonly array $missing = [])
    {
        parent::__construct('Core processing gate (L-core) is closed: '.implode(', ', $missing));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Parking;

use RuntimeException;

/**
 * A sighting photo was refused (format | size | pixels) — the sheet shows the friendly message.
 */
final class PhotoRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Sighting photo rejected: '.$reason);
    }
}

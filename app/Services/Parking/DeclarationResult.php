<?php

declare(strict_types=1);

namespace App\Services\Parking;

/**
 * Outcome of a resident vehicle declaration. Deliberately carries NO information about the registry: a plate that is
 * already active in another apartment gets exactly the same `pending` answer as a fresh one (no registration oracle,
 * MVP-SPEC C.5 / AC 16).
 */
final readonly class DeclarationResult
{
    public const PENDING = 'pending';

    public const INVALID = 'invalid';

    public const RATE_LIMITED = 'rate_limited';

    public function __construct(
        public string $status,
        public ?int $retryAfter = null,
    ) {}
}

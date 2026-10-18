<?php

declare(strict_types=1);

namespace App\Services\Parking;

/**
 * Outcome of a plate lookup (PARK-002). The ONLY datum a resident ever receives about the registry is
 * `registered` (yes / no) — never an apartment, permit type or history.
 */
final readonly class LookupResult
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const RATE_LIMITED = 'rate_limited';

    public function __construct(
        public string $status,
        public ?bool $registered = null,
        public ?int $retryAfter = null,
    ) {}

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function limited(int $retryAfter): self
    {
        return new self(self::RATE_LIMITED, retryAfter: max(1, $retryAfter));
    }

    public function ok(): bool
    {
        return $this->status === self::OK;
    }
}

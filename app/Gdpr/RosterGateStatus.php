<?php

declare(strict_types=1);

namespace App\Gdpr;

/**
 * Result of RosterGate::status(): open + the missing condition keys (RosterGate::CONDITIONS).
 */
final readonly class RosterGateStatus
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(
        public bool $open,
        public array $missing,
    ) {}

    /**
     * @return list<string>
     */
    public function missingLabels(): array
    {
        return array_map(static fn (string $k): string => __('gate.roster.missing.'.$k), $this->missing);
    }
}

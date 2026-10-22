<?php

declare(strict_types=1);

namespace App\Gdpr;

/**
 * Result of RosterGate::status(): open, and the missing condition keys (RosterGate::CONDITIONS).
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
     * Plain-language labels of the missing conditions (current locale).
     *
     * @return list<string>
     */
    public function missingLabels(): array
    {
        return array_map(static fn (string $k): string => __('gate.roster.missing.'.$k), $this->missing);
    }
}

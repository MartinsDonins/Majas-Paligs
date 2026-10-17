<?php

declare(strict_types=1);

namespace App\Gdpr;

final readonly class CoreGateStatus
{
    /**
     * @param  list<string>  $missing  condition keys (CoreGate::CONDITIONS)
     */
    public function __construct(
        public bool $open,
        public array $missing,
        public ?int $noticeId = null,
    ) {}

    /**
     * Translated list of missing conditions for admin UIs.
     *
     * @return list<string>
     */
    public function missingLabels(): array
    {
        return array_map(static fn (string $k): string => __('gate.core.missing.'.$k), $this->missing);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Heating\Views;

use App\Enums\AggregateStatus;

/**
 * Column aggregate (every resident cell of one grid column shows it). Counts only.
 */
final readonly class ColumnView
{
    public function __construct(
        public int $sectionId,
        public int $position,
        public ?AggregateStatus $worstConfirmed,
        public ?AggregateStatus $unconfirmed,
        public int $distinctApartments,
        public int $floorsCovered,
        public int $floorsTotal,
        public int $coverageTotal,
        public bool $disputed,
        public bool $checked,
        public bool $conflict,
    ) {}

    public static function empty(int $sectionId, int $position, int $floorsTotal): self
    {
        return new self($sectionId, $position, null, null, 0, 0, $floorsTotal, 0, false, false, false);
    }

    /**
     * disputed | none | unconfirmed | cold | lukewarm | warm | overheated | mixed
     */
    public function displayState(): string
    {
        return match (true) {
            $this->disputed => 'disputed',
            $this->worstConfirmed !== null => $this->worstConfirmed->value,
            $this->unconfirmed !== null => 'unconfirmed',
            default => 'none',
        };
    }

    /**
     * Status whose colour draws the unconfirmed outline (or the confirmed fill).
     */
    public function tone(): ?AggregateStatus
    {
        return $this->worstConfirmed ?? $this->unconfirmed;
    }
}

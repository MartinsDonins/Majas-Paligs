<?php

declare(strict_types=1);

namespace App\Services\Outages;

/**
 * One outage scope: `riser:{id}` | `col:{section_id}:{position}` | `section:{id}` | `building`.
 */
final readonly class ScopeTarget
{
    private function __construct(
        public string $key,
        public ?int $sectionId,
        public ?int $riserId,
        public ?int $position,
    ) {}

    public static function riser(int $riserId, int $sectionId): self
    {
        return new self('riser:'.$riserId, $sectionId, $riserId, null);
    }

    public static function column(int $sectionId, int $position): self
    {
        return new self('col:'.$sectionId.':'.$position, $sectionId, null, $position);
    }

    public static function section(int $sectionId): self
    {
        return new self('section:'.$sectionId, $sectionId, null, null);
    }

    public static function building(): self
    {
        return new self('building', null, null, null);
    }
}

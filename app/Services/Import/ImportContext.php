<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * State shared by the rows of one validation pass: the building, the run options (mode create_only |
 * create_or_update), the apartment map, the in-file duplicate keys and a cache the handler fills in prepare().
 */
final class ImportContext
{
    public const CREATE_ONLY = 'create_only';

    public const CREATE_OR_UPDATE = 'create_or_update';

    /** @var array<string, int> dedupe key => first row number */
    public array $seen = [];

    /** @var array<string, mixed> handler-owned (ImportHandler::prepare) */
    public array $cache = [];

    /**
     * @param  array<int, array{id: int, section_id: int}>  $apartments  active apartment number => ids
     * @param  array<int, array{id: int, section_id: int, number: int}>  $retired  retired number => primary apartment
     */
    public function __construct(
        public readonly int $buildingId,
        public readonly string $mode,
        public readonly array $apartments,
        public readonly array $retired,
    ) {}

    public function updates(): bool
    {
        return $this->mode === self::CREATE_OR_UPDATE;
    }
}

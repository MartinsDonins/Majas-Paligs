<?php

declare(strict_types=1);

namespace App\Services\Parking;

/**
 * Where a sealed plate lives (AEAD associated data, MVP-SPEC C.1): building + table + row id (+ key version).
 * A ciphertext copied to another row, table or building fails to open.
 */
final readonly class VaultContext
{
    public const TABLES = ['vehicles', 'sightings'];

    public function __construct(
        public int $buildingId,
        public string $table,
        public string $rowId,
    ) {
        if (! in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException("PlateVault: unknown table [{$table}].");
        }
    }

    public function associatedData(int $keyVersion): string
    {
        return "mp:plate:v{$keyVersion}|b:{$this->buildingId}|t:{$this->table}|r:{$this->rowId}";
    }
}

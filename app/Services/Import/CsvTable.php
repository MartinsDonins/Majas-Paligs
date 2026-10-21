<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * A parsed CSV (CsvReader::parse): UTF-8 NFC cells, the encoding and delimiter that were used. Lives in memory only
 * (the file itself is kept sealed in import_runs.payload_ciphertext) — never put it into a Livewire property.
 */
final readonly class CsvTable
{
    /**
     * @param  list<list<string>>  $rows  every row incl. a header row, cells normalised (CsvReader::cell)
     */
    public function __construct(
        public array $rows,
        public string $encoding,
        public string $delimiter,
    ) {}

    public function columnCount(): int
    {
        return $this->rows === [] ? 0 : max(array_map('count', $this->rows));
    }

    /**
     * @return list<string>
     */
    public function firstRow(): array
    {
        return $this->rows[0] ?? [];
    }

    /**
     * Data rows (without the header when $hasHeader), keyed by their 1-based row number in the FILE.
     *
     * @return array<int, list<string>>
     */
    public function dataRows(bool $hasHeader): array
    {
        $out = [];
        foreach ($this->rows as $i => $row) {
            if ($hasHeader && $i === 0) {
                continue;
            }
            $out[$i + 1] = $row;
        }

        return $out;
    }
}

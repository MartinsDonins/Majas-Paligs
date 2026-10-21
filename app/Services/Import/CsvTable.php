<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * A parsed CSV (CsvReader::read): UTF-8 NFC cells, the encoding and delimiter that were used, and every non-empty
 * record keyed by its 1-based record number in the file (the report shows these numbers), including a possible
 * header record — the caller decides `has_header`.
 */
final readonly class CsvTable
{
    /**
     * @param  array<int, list<string>>  $rows  record number => cells
     */
    public function __construct(
        public string $encoding,
        public string $delimiter,
        public array $rows,
        public bool $encodingGuessed = false,
    ) {}

    /**
     * @return list<string>
     */
    public function firstRow(): array
    {
        return $this->rows === [] ? [] : $this->rows[array_key_first($this->rows)];
    }

    /**
     * @return list<string>
     */
    public function header(bool $hasHeader): array
    {
        return $hasHeader ? $this->firstRow() : [];
    }

    /**
     * @return array<int, list<string>> record number => cells
     */
    public function dataRows(bool $hasHeader): array
    {
        $rows = $this->rows;
        if ($hasHeader && $rows !== []) {
            unset($rows[array_key_first($rows)]);
        }

        return $rows;
    }

    public function columnCount(): int
    {
        return $this->rows === [] ? 0 : max(array_map('count', $this->rows));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * One data row after mapping: field key => cell value (a `multi` field => list of cell values, one per mapped column).
 * In memory only.
 */
final readonly class ImportRow
{
    /**
     * @param  array<string, string|list<string>>  $values
     */
    public function __construct(
        public int $number,
        public array $values,
    ) {}

    public function get(string $field): string
    {
        $value = $this->values[$field] ?? '';

        return is_array($value) ? trim(implode(', ', array_filter($value, static fn (string $v): bool => $v !== ''))) : $value;
    }

    /**
     * @return list<string>
     */
    public function all(string $field): array
    {
        $value = $this->values[$field] ?? [];

        return array_values(array_filter(is_array($value) ? $value : [$value], static fn (string $v): bool => $v !== ''));
    }

    public function has(string $field): bool
    {
        return $this->get($field) !== '';
    }
}

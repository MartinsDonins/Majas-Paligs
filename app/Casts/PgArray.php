<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts PostgreSQL one-dimensional text[] / int[] / bigint[] columns to PHP lists.
 * Usage: 'covers_modules' => PgArray::class, 'retired_numbers' => PgArray::class.':int'.
 *
 * @implements CastsAttributes<list<string|int>, list<string|int>>
 */
final class PgArray implements CastsAttributes
{
    public function __construct(private string $type = 'text') {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string|int>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        $inner = substr((string) $value, 1, -1);
        $items = str_getcsv($inner, ',', '"', '\\');

        return array_values(array_map(
            fn (?string $v): string|int => $this->type === 'int' ? (int) $v : (string) $v,
            array_filter($items, static fn (?string $v): bool => $v !== null && $v !== 'NULL'),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return self::literal((array) ($value ?? []), $this->type);
    }

    /**
     * Build a PostgreSQL array literal.
     *
     * @param  array<int|string, mixed>  $values
     */
    public static function literal(array $values, string $type = 'text'): string
    {
        $parts = array_map(static function (mixed $v) use ($type): string {
            if ($type === 'int') {
                return (string) (int) $v;
            }

            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"';
        }, array_values($values));

        return '{'.implode(',', $parts).'}';
    }
}

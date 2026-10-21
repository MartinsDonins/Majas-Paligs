<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * PostgreSQL bytea[] ↔ PHP list of binary strings (blind indexes of resident_contacts.phone_bidx). Written as an
 * array literal of hex-form elements ({"\\x…","\\x…"}), read back from the same text form.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class ByteaArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return self::decode($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return self::literal(array_values((array) ($value ?? [])));
    }

    /**
     * Array literal for a list of binary strings (bind as an ordinary parameter, cast with ::bytea[]).
     *
     * @param  list<string>  $values
     */
    public static function literal(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $b): string => '"\\\\x'.bin2hex($b).'"', $values)).'}';
    }

    /**
     * @return list<string>
     */
    public static function decode(mixed $value): array
    {
        if (is_resource($value)) {
            $value = (string) stream_get_contents($value, null, 0);
        }

        $value = trim((string) $value);
        if ($value === '' || $value === '{}') {
            return [];
        }

        $out = [];
        foreach (explode(',', substr($value, 1, -1)) as $item) {
            $decoded = Bytea::decode(str_replace('\\\\', '\\', trim($item, '"')));
            if ($decoded !== null && $decoded !== '') {
                $out[] = $decoded;
            }
        }

        return $out;
    }
}

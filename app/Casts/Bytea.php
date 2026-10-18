<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * PostgreSQL bytea ↔ PHP binary string. PDO_pgsql returns bytea as a stream (or as the "\x…" hex text form);
 * values are written in the hex input form "\x…" so they can be bound as ordinary string parameters.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class Bytea implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::decode($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : self::literal((string) $value);
    }

    /**
     * Hex input form for a binary string (bind it as a normal parameter: `where('plate_bidx', Bytea::literal($b))`).
     */
    public static function literal(string $binary): string
    {
        return '\\x'.bin2hex($binary);
    }

    public static function decode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            // Read from offset 0: Eloquent calls the cast on every access and the raw attribute stays the same stream.
            $value = (string) stream_get_contents($value, null, 0);
        }

        $value = (string) $value;

        if (str_starts_with($value, '\\x')) {
            $decoded = hex2bin(substr($value, 2));

            return $decoded === false ? '' : $decoded;
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Parking;

/**
 * PlateNormalizer (PARK-001.1, PLANNING §4.7): uppercase, strip spaces / dashes / dots / other separators, map
 * Cyrillic look-alikes to Latin, then require `[A-Z0-9]{1,8}`. "ab 1234", "AB-1234" and "АВ1234" (Cyrillic) all
 * normalize to "AB1234". Exact-match only: partial strings never match (the index is a keyed hash).
 */
final class PlateNormalizer
{
    /** Cyrillic letters that look like Latin plate letters (upper case after mb_strtoupper). */
    private const CYRILLIC = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P',
        'С' => 'C', 'Т' => 'T', 'У' => 'Y', 'Х' => 'X', 'І' => 'I', 'Ј' => 'J', 'Ѕ' => 'S', 'Ԛ' => 'Q', 'Ԝ' => 'W',
    ];

    /** Latin letters with diacritics that a phone keyboard may produce. */
    private const LATIN = [
        'Ā' => 'A', 'Č' => 'C', 'Ē' => 'E', 'Ģ' => 'G', 'Ī' => 'I', 'Ķ' => 'K', 'Ļ' => 'L', 'Ņ' => 'N', 'Š' => 'S',
        'Ū' => 'U', 'Ž' => 'Z', 'Ö' => 'O', 'Ä' => 'A', 'Ü' => 'U', 'Õ' => 'O',
    ];

    public const MAX_LENGTH = 8;

    /**
     * Normalized plate or null when the input cannot be a plate (empty, too long, other characters).
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $value = mb_strtoupper(trim($input), 'UTF-8');
        $value = strtr($value, self::CYRILLIC + self::LATIN);
        // Separators people type: spaces (incl. NBSP), dashes, dots, middle dots, underscores, slashes.
        $value = (string) preg_replace('/[\s\x{00A0}\-\x{2010}-\x{2015}\.\x{00B7}_\/]+/u', '', $value);

        if (preg_match('/^[A-Z0-9]{1,'.self::MAX_LENGTH.'}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * ISO 3166-1 alpha-2 country (default LV); anything else falls back to LV.
     */
    public static function country(?string $country): string
    {
        $value = strtoupper(trim((string) $country));

        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : 'LV';
    }

    /**
     * Masked display for staff lists: "AB1234" → "AB••34" (first two and last two characters).
     */
    public static function mask(string $normalized): string
    {
        $len = strlen($normalized);

        if ($len <= 3) {
            return str_repeat('•', $len);
        }

        return substr($normalized, 0, 2).str_repeat('•', max(1, $len - 4)).substr($normalized, -2);
    }
}

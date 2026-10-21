<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phone numbers of the resident roster (ROUND2-SPEC §4.5). Output is E.164 (`+` and digits only).
 *
 * - strip spaces, dots, dashes and brackets; a leading `00` becomes `+`;
 * - 8 digits starting with 2 (mobile) or 6 (landline) get the Latvian `+371` prefix;
 * - Latvia valid = `+371` followed by 8 digits whose first digit is 2 or 6;
 * - any other `+[1-9][0-9]{6,14}` is a valid FOREIGN number (an import warning, never an error);
 * - one cell may hold several numbers separated by `,` `;` `/` `|` " un " " и ".
 */
final class PhoneNormalizer
{
    public const LATVIA = 'lv';

    public const FOREIGN = 'foreign';

    /**
     * E.164 form, or null when the value is not a phone number.
     */
    public static function normalize(?string $value): ?string
    {
        return self::classify($value)['e164'];
    }

    /**
     * @return array{e164: ?string, kind: ?string} kind = lv | foreign | null (invalid)
     */
    public static function classify(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return ['e164' => null, 'kind' => null];
        }

        $compact = (string) preg_replace('/[\s.\-()\x{00A0}]/u', '', $value);
        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }
        if (preg_match('/^[26]\d{7}$/', $compact) === 1) {
            $compact = '+371'.$compact;
        }
        if (preg_match('/^371[26]\d{7}$/', $compact) === 1) {
            $compact = '+'.$compact;
        }

        if (preg_match('/^\+371\d+$/', $compact) === 1) {
            return preg_match('/^\+371[26]\d{7}$/', $compact) === 1
                ? ['e164' => $compact, 'kind' => self::LATVIA]
                : ['e164' => null, 'kind' => null];
        }

        if (preg_match('/^\+[1-9]\d{6,14}$/', $compact) === 1) {
            return ['e164' => $compact, 'kind' => self::FOREIGN];
        }

        return ['e164' => null, 'kind' => null];
    }

    /**
     * Split one spreadsheet cell into the separate numbers people typed into it.
     *
     * @return list<string> raw parts (not yet normalised), empty parts dropped
     */
    public static function split(?string $cell): array
    {
        $parts = preg_split('/\s*(?:[,;\/|]|\sun\s|\sи\s)\s*/iu', trim((string) $cell)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }

    /**
     * Masked display form: `+371 2•••••45` (country code, first digit, bullets, last two digits).
     */
    public static function mask(string $e164): string
    {
        $digits = ltrim($e164, '+');

        if (str_starts_with($e164, '+371') && strlen($digits) === 11) {
            return '+371 '.$digits[3].str_repeat('•', 5).substr($digits, -2);
        }

        return '+'.substr($digits, 0, 2).str_repeat('•', max(3, strlen($digits) - 4)).substr($digits, -2);
    }

    /**
     * Last two digits — what a screen reader announces for a masked number ("beidzas ar 45").
     */
    public static function lastDigits(string $e164): string
    {
        return substr($e164, -2);
    }
}

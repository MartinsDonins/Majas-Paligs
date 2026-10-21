<?php

declare(strict_types=1);

namespace App\Gdpr;

/**
 * Formula-injection-safe CSV (OWASP): cells starting with = + - @ TAB CR are prefixed with an apostrophe. Delimiter `,`
 * by default; `;` for the lists Excel LV opens directly (ROUND2-SPEC §6.4).
 */
final class CsvWriter
{
    /**
     * @param  list<list<scalar|null>>  $rows
     * @param  string  $delimiter  `,` (default) or `;` (Excel in a Latvian locale, roster files)
     */
    public static function toString(array $rows, string $delimiter = ','): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for spreadsheet apps (Latvian diacritics, Cyrillic)

        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::safe(...), $row), $delimiter, '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public static function safe(mixed $value): string
    {
        $value = $value === null ? '' : (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}

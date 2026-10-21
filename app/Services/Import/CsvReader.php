<?php

declare(strict_types=1);

namespace App\Services\Import;

use Normalizer;

/**
 * CSV parsing for the import pipeline (DB-003, ROUND2-SPEC §4.3). Input is a sealed upload or pasted text, never a
 * URL. Caps: 2 MB, 2000 data rows, 30 columns, 1000 characters per cell. Encoding: UTF-8 (BOM stripped), otherwise
 * Windows-1257 or Windows-1251 by heuristic (overridable). Delimiter `;` `,` TAB sniffed outside quotes over the
 * first 10 lines (overridable). RFC 4180 quoting with `""` escapes and multi-line quoted cells. Cells are trimmed,
 * whitespace collapsed, a leading Excel text apostrophe stripped and `-`, `—`, `n/a`, `nav` treated as empty;
 * formulas are never evaluated (values stay text; CsvWriter re-escapes them on every export).
 *
 * Errors are ImportFailed keys only — a message never contains a cell value.
 */
final class CsvReader
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_ROWS = 2000;

    public const MAX_COLUMNS = 30;

    public const MAX_CELL = 1000;

    public const ENCODINGS = ['utf-8', 'windows-1257', 'windows-1251'];

    public const DELIMITERS = [';' => ';', ',' => ',', 'tab' => "\t"];

    private const EXTENSIONS = ['csv', 'txt'];

    private const MIME_TYPES = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'];

    private const EMPTY_TOKENS = ['-', '—', '–', 'n/a', 'nav', 'н/д'];

    /**
     * Upload check: extension + finfo type + size (`file_type` / `too_large`).
     */
    public function assertUpload(string $path, string $originalName): void
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = is_file($path) ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path) : '';

        if (! in_array($ext, self::EXTENSIONS, true) || ! in_array($mime, self::MIME_TYPES, true)) {
            throw new ImportFailed('file_type');
        }

        if ((int) filesize($path) > self::MAX_BYTES) {
            throw new ImportFailed('too_large');
        }
    }

    /**
     * Parse raw bytes. $encoding / $delimiter null = detect.
     *
     * @throws ImportFailed empty | too_large | too_many_rows | too_many_columns | cell_too_long | encoding
     */
    public function read(string $bytes, ?string $encoding = null, ?string $delimiter = null): CsvTable
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ImportFailed('too_large');
        }

        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
            $encoding ??= 'utf-8';
        }

        $guessed = false;
        if ($encoding === null || ! in_array($encoding, self::ENCODINGS, true)) {
            $encoding = self::detectEncoding($bytes);
            $guessed = $encoding !== 'utf-8';
        }

        $text = self::toUtf8($bytes, $encoding);

        if (trim($text) === '') {
            throw new ImportFailed('empty');
        }

        if ($delimiter === null || ! array_key_exists($delimiter, self::DELIMITERS)) {
            $delimiter = self::sniffDelimiter($text);
        }

        $records = self::parse($text, self::DELIMITERS[$delimiter]);
        $rows = [];

        foreach ($records as $number => $cells) {
            $cells = array_map(self::normaliseCell(...), $cells);
            // Trailing empty cells (Excel pads rows to the widest one) do not count as columns.
            while ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }
            if ($cells === []) {
                continue;
            }
            if (count($cells) > self::MAX_COLUMNS) {
                throw new ImportFailed('too_many_columns');
            }
            foreach ($cells as $cell) {
                if (mb_strlen($cell) > self::MAX_CELL) {
                    throw new ImportFailed('cell_too_long');
                }
            }
            $rows[$number] = $cells;

            // One header row + MAX_ROWS data rows at most.
            if (count($rows) > self::MAX_ROWS + 1) {
                throw new ImportFailed('too_many_rows');
            }
        }

        if ($rows === []) {
            throw new ImportFailed('empty');
        }

        return new CsvTable($encoding, $delimiter, $rows, $guessed);
    }

    /**
     * utf-8 when the bytes are valid UTF-8; otherwise by letter statistics: in Windows-1251 Cyrillic text almost every
     * letter is a byte ≥ 0xC0, in Windows-1257 Latvian text only the diacritics are (roughly one letter in ten). The
     * page shows the guess and lets the admin change it.
     */
    public static function detectEncoding(string $bytes): string
    {
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return 'utf-8';
        }

        $high = preg_match_all('/[\xC0-\xFF]/', $bytes);
        $ascii = preg_match_all('/[A-Za-z]/', $bytes);

        return $high > 0 && $high / max(1, $high + $ascii) > 0.4 ? 'windows-1251' : 'windows-1257';
    }

    /**
     * Convert to UTF-8 NFC.
     *
     * @throws ImportFailed encoding
     */
    public static function toUtf8(string $bytes, string $encoding): string
    {
        if ($encoding === 'utf-8') {
            if (! mb_check_encoding($bytes, 'UTF-8')) {
                throw new ImportFailed('encoding');
            }
            $text = $bytes;
        } else {
            $text = @iconv(strtoupper($encoding), 'UTF-8', $bytes);
            if (! is_string($text)) {
                throw new ImportFailed('encoding');
            }
        }

        $nfc = Normalizer::normalize($text, Normalizer::FORM_C);

        return is_string($nfc) ? $nfc : $text;
    }

    /**
     * `;`, `,` or `tab` — the separator with the most consistent non-zero count outside quotes over the first 10
     * records (ties: `;` before `,` before TAB, the Excel LV default first).
     */
    public static function sniffDelimiter(string $text): string
    {
        $best = ';';
        $bestScore = -1;
        $text = rtrim($text, "\r\n");

        foreach (self::DELIMITERS as $key => $char) {
            $counts = [];
            $inQuotes = false;
            $count = 0;
            $len = strlen($text);

            for ($i = 0; $i < $len && count($counts) < 10; $i++) {
                $c = $text[$i];
                if ($c === '"') {
                    $inQuotes = ! $inQuotes;
                } elseif (! $inQuotes && ($c === "\n" || $c === "\r")) {
                    if ($c === "\r" && ($text[$i + 1] ?? '') === "\n") {
                        $i++;
                    }
                    $counts[] = $count;
                    $count = 0;
                } elseif (! $inQuotes && $c === $char) {
                    $count++;
                }
            }
            if (count($counts) < 10) {
                $counts[] = $count;
            }

            $nonEmpty = array_values(array_filter($counts, static fn (int $n): bool => $n > 0));
            if ($nonEmpty === []) {
                continue;
            }
            $consistent = count($nonEmpty) === count($counts) && count(array_unique($nonEmpty)) === 1;
            $score = ($consistent ? 1000 : 0) + count($nonEmpty) * 10 + min($nonEmpty);

            if ($score > $bestScore) {
                $best = $key;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * RFC 4180 parser: quoted cells may contain the delimiter, newlines and `""` escapes.
     *
     * @return array<int, list<string>> record number (1-based) => raw cells
     */
    public static function parse(string $text, string $delimiter): array
    {
        $records = [];
        $row = [];
        $cell = '';
        $inQuotes = false;
        $number = 1;
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];

            if ($inQuotes) {
                if ($c === '"') {
                    if (($text[$i + 1] ?? '') === '"') {
                        $cell .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $cell .= $c;
                }

                continue;
            }

            if ($c === '"' && trim($cell) === '') {
                $inQuotes = true;
                $cell = '';
            } elseif ($c === $delimiter) {
                $row[] = $cell;
                $cell = '';
            } elseif ($c === "\n" || $c === "\r") {
                if ($c === "\r" && ($text[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $row[] = $cell;
                $records[$number++] = $row;
                $row = [];
                $cell = '';
            } else {
                $cell .= $c;
            }
        }

        if ($cell !== '' || $row !== []) {
            $row[] = $cell;
            $records[$number] = $row;
        }

        return $records;
    }

    public static function normaliseCell(string $value): string
    {
        $value = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));

        if (str_starts_with($value, "'")) {
            $value = ltrim(substr($value, 1));
        }

        if (in_array(mb_strtolower($value), self::EMPTY_TOKENS, true)) {
            return '';
        }

        return $value;
    }
}

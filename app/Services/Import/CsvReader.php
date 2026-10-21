<?php

declare(strict_types=1);

namespace App\Services\Import;

use Normalizer;
use UConverter;

/**
 * CSV parsing for the import pipeline (DB-003, ROUND2-SPEC §4.3). Exports of Excel ("CSV UTF-8" = `;` + BOM in a
 * Latvian locale), Google Sheets (`,` + UTF-8) and older Windows exports (Windows-1257 / Windows-1251) all work:
 *
 * - encoding: a UTF-8 BOM is stripped; valid UTF-8 → `utf-8`; otherwise the share of high bytes among letters picks
 *   Windows-1251 (Cyrillic text is almost all high bytes) or Windows-1257 (Latvian: mostly ASCII + diacritics);
 *   the page lets the person change it. Output is always UTF-8 NFC.
 * - delimiter: `;`, `,` or TAB sniffed over the first 10 records outside quotes (override possible).
 * - RFC 4180 quoting (`""` escape, multi-line quoted cells).
 * - caps: ≤ 2 MB, ≤ 2000 data rows, ≤ 30 columns, ≤ 1000 characters per cell — otherwise ImportRefused.
 * - cells: trimmed, inner whitespace collapsed, a leading `'` (Excel text prefix) removed, `-` `—` `n/a` `nav` = empty.
 *   Formulas are never evaluated; a value starting with `= + - @` stays text (CsvWriter re-escapes it on export).
 * Never fetches a URL (no "import from a Google Sheets link", ever).
 */
final class CsvReader
{
    public const ENCODINGS = ['utf-8', 'windows-1257', 'windows-1251'];

    /** Stored delimiter key => character. */
    public const DELIMITERS = [';' => ';', ',' => ',', 'tab' => "\t"];

    public const EXTENSIONS = ['csv', 'txt'];

    public const MIME_TYPES = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'];

    private const EMPTY_MARKERS = ['-', '—', '–', 'n/a', 'nav'];

    /**
     * Upload checks before the bytes are read: extension `.csv` / `.txt` and a text-like finfo type.
     *
     * @throws ImportRefused file_type | file_too_large | file_empty
     */
    public function assertUpload(string $path, string $originalName): void
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new ImportRefused('file_type');
        }

        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw new ImportRefused('file_empty');
        }
        if ($size > (int) config('majas.import.max_bytes')) {
            throw new ImportRefused('file_too_large');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, self::MIME_TYPES, true)) {
            throw new ImportRefused('file_type');
        }
    }

    /**
     * @throws ImportRefused
     */
    public function parse(string $bytes, ?string $encoding = null, ?string $delimiter = null): CsvTable
    {
        if (strlen($bytes) > (int) config('majas.import.max_bytes')) {
            throw new ImportRefused('file_too_large');
        }

        $encoding = in_array($encoding, self::ENCODINGS, true) ? $encoding : $this->detectEncoding($bytes);
        $text = $this->toUtf8($bytes, $encoding);

        if (trim($text) === '') {
            throw new ImportRefused('file_empty');
        }
        // Binary content (spreadsheet files renamed to .csv) never parses as text.
        if (str_contains($text, "\0")) {
            throw new ImportRefused('file_type');
        }

        $delimiter = array_key_exists((string) $delimiter, self::DELIMITERS) ? (string) $delimiter : $this->sniffDelimiter($text);
        $rows = $this->records($text, self::DELIMITERS[$delimiter]);

        $rows = array_values(array_filter($rows, static fn (array $r): bool => implode('', $r) !== ''));
        if ($rows === []) {
            throw new ImportRefused('file_empty');
        }
        if (count($rows) > (int) config('majas.import.max_rows') + 1) {
            throw new ImportRefused('too_many_rows');
        }

        $maxCell = (int) config('majas.import.max_cell_chars');
        foreach ($rows as $row) {
            if (count($row) > (int) config('majas.import.max_columns')) {
                throw new ImportRefused('too_many_columns');
            }
            foreach ($row as $cell) {
                if (mb_strlen($cell) > $maxCell) {
                    throw new ImportRefused('cell_too_long');
                }
            }
        }

        return new CsvTable($rows, $encoding, $delimiter);
    }

    public function detectEncoding(string $bytes): string
    {
        $bytes = self::stripBom($bytes);

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return 'utf-8';
        }

        $high = preg_match_all('/[\xC0-\xFF]/', $bytes);
        $ascii = preg_match_all('/[A-Za-z]/', $bytes);

        // Russian text: nearly every letter is a high byte. Latvian text: mostly ASCII letters + a few diacritics.
        return $high > 0 && $high / max(1, $high + $ascii) > 0.4 ? 'windows-1251' : 'windows-1257';
    }

    public function toUtf8(string $bytes, string $encoding): string
    {
        $bytes = self::stripBom($bytes);

        if ($encoding !== 'utf-8') {
            $converted = @iconv(strtoupper($encoding), 'UTF-8//IGNORE', $bytes);
            if (! is_string($converted) && class_exists(UConverter::class)) {
                // ICU names Windows-125x ambiguously ("ibm-…"): a warning, not a failure — silence it.
                $converted = @UConverter::transcode($bytes, 'UTF-8', $encoding);
            }
            $bytes = is_string($converted) ? $converted : '';
        } elseif (! mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_scrub($bytes, 'UTF-8');
        }

        $normalized = Normalizer::normalize($bytes, Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $bytes;
    }

    /**
     * Stored delimiter key (`;` `,` `tab`) with the most consistent count outside quotes over the first 10 records.
     */
    public function sniffDelimiter(string $text): string
    {
        $best = ';';
        $bestScore = 0;

        foreach (self::DELIMITERS as $key => $char) {
            $counts = [];
            foreach (array_slice($this->records($text, $char, 10, raw: true), 0, 10) as $record) {
                $counts[] = count($record) - 1;
            }
            $counts = array_values(array_filter($counts, static fn (int $c): bool => $c > 0));
            if ($counts === []) {
                continue;
            }
            // Many records with the same number of separators beat a single long line.
            $frequency = array_count_values($counts);
            arsort($frequency);
            $score = (int) reset($frequency) * 100 + (int) array_key_first($frequency);

            if ($score > $bestScore) {
                $best = $key;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * One normalised cell value.
     */
    public static function cell(string $raw): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $raw));

        if (str_starts_with($value, "'")) {
            $value = ltrim(substr($value, 1));
        }

        return in_array(mb_strtolower($value), self::EMPTY_MARKERS, true) ? '' : $value;
    }

    /**
     * RFC 4180 records (quoted cells may contain the delimiter, `""` and line breaks).
     *
     * @return list<list<string>>
     */
    private function records(string $text, string $delimiter, ?int $limit = null, bool $raw = false): array
    {
        $records = [];
        $row = [];
        $cell = '';
        $quoted = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];

            if ($quoted) {
                if ($ch === '"') {
                    if (($text[$i + 1] ?? '') === '"') {
                        $cell .= '"';
                        $i++;
                    } else {
                        $quoted = false;
                    }
                } else {
                    $cell .= $ch;
                }

                continue;
            }

            if ($ch === '"' && trim($cell) === '') {
                $quoted = true;
                $cell = '';
            } elseif ($ch === $delimiter) {
                $row[] = $cell;
                $cell = '';
            } elseif ($ch === "\n" || $ch === "\r") {
                if ($ch === "\r" && ($text[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $row[] = $cell;
                $records[] = $row;
                $row = [];
                $cell = '';
                if ($limit !== null && count($records) >= $limit) {
                    break;
                }
            } else {
                $cell .= $ch;
            }
        }

        if ($cell !== '' || $row !== []) {
            $row[] = $cell;
            $records[] = $row;
        }

        if ($raw) {
            return $records;
        }

        return array_map(static fn (array $r): array => array_map(self::cell(...), $r), $records);
    }

    private static function stripBom(string $bytes): string
    {
        return str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
    }
}

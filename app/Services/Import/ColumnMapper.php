<?php

declare(strict_types=1);

namespace App\Services\Import;

use Normalizer;

/**
 * Column mapping (DB-003, ROUND2-SPEC §4.4). Headers are compared normalised (lower-case, unaccented, punctuation
 * stripped) against each field's LV / RU / EN synonyms. A mapping is `{"<column index>": "<field key>"}`; unmapped
 * columns are ignored ("netiks importēts"). Header lists are not personal data, so a mapping is remembered per header
 * layout (`import_mapping_presets`, signature = sha256 of the normalised header list).
 */
final class ColumnMapper
{
    /**
     * Normalised header / synonym form: "Dz. Nr." → "dz nr", "E-pasts" → "e pasts", "Квартира №" → "квартира".
     */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
        $value = (string) preg_replace('/\p{Mn}+/u', '', is_string($decomposed) ? $decomposed : $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Suggested mapping for a header row (first matching column wins for single fields).
     *
     * @param  list<string>  $headers
     * @return array<int|string, string> column index => field key
     */
    public function suggest(ImportHandler $handler, array $headers): array
    {
        $mapping = [];
        $taken = [];

        foreach ($headers as $index => $header) {
            $field = $this->match($handler, $header);
            if ($field === null) {
                continue;
            }
            $definition = $handler->fields()[$field];
            if (! $definition->multi && in_array($field, $taken, true)) {
                continue;
            }
            $mapping[(string) $index] = $field;
            $taken[] = $field;
        }

        return $mapping;
    }

    /**
     * The field a single header means, or null.
     */
    public function match(ImportHandler $handler, string $header): ?string
    {
        $normalized = self::normalize($header);
        if ($normalized === '') {
            return null;
        }
        // "Telefons 2", "Tālr. (mob.)" → compare without trailing numbers / bracketed notes as well.
        $stripped = trim((string) preg_replace('/(\s+\d+)+$/', '', $normalized));

        foreach ([$normalized, $stripped] as $candidate) {
            foreach ($handler->fields() as $key => $field) {
                foreach ([$key, ...$field->synonyms] as $synonym) {
                    if (self::normalize(str_replace('_', ' ', $synonym)) === $candidate) {
                        return $key;
                    }
                }
            }
        }

        // Whole-word containment for longer synonyms ("Mobilais telefons", "Vārds, uzvārds").
        foreach ($handler->fields() as $key => $field) {
            foreach ($field->synonyms as $synonym) {
                $s = self::normalize($synonym);
                if (mb_strlen($s) >= 4 && preg_match('/(^| )'.preg_quote($s, '/').'( |$)/u', $normalized) === 1) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Header row auto-detection: at least half of the non-empty first-row cells match a synonym.
     *
     * @param  list<string>  $firstRow
     */
    public function looksLikeHeader(ImportHandler $handler, array $firstRow): bool
    {
        $cells = array_values(array_filter($firstRow, static fn (string $c): bool => trim($c) !== ''));
        if ($cells === []) {
            return false;
        }

        $matches = count(array_filter($cells, fn (string $c): bool => $this->match($handler, $c) !== null));

        return $matches * 2 >= count($cells);
    }

    /**
     * sha256 (binary) of the normalised header list — the preset key.
     *
     * @param  list<string>  $headers
     */
    public static function signature(array $headers): string
    {
        return hash('sha256', (string) json_encode(array_map(self::normalize(...), $headers), JSON_UNESCAPED_UNICODE), true);
    }

    /**
     * Mapping errors: required fields missing (`mapping_required`) or a single field mapped twice (`mapping_duplicate`).
     *
     * @param  array<int|string, string>  $mapping
     * @return list<string> error keys (empty = OK)
     */
    public function problems(ImportHandler $handler, array $mapping, int $columns): array
    {
        $fields = $handler->fields();
        $problems = [];
        $counts = array_count_values(array_values($mapping));

        foreach ($mapping as $index => $field) {
            if (! isset($fields[$field]) || ! ctype_digit((string) $index) || (int) $index >= $columns) {
                $problems[] = 'mapping_invalid';
            }
        }
        foreach ($counts as $field => $count) {
            if (isset($fields[$field]) && ! $fields[$field]->multi && $count > 1) {
                $problems[] = 'mapping_duplicate';
            }
        }
        foreach ($fields as $key => $field) {
            if ($field->required && ! isset($counts[$key])) {
                $problems[] = 'mapping_required';
            }
        }

        return array_values(array_unique($problems));
    }
}

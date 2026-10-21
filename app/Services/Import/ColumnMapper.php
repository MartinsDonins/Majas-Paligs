<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Casts\Bytea;
use App\Enums\ImportKind;
use App\Models\ImportMappingPreset;
use App\Models\User;
use App\Tenancy\CurrentBuilding;

/**
 * Column mapping (DB-003, ROUND2-SPEC §4.4): auto-suggest by normalised header (lowercase, unaccented, punctuation
 * stripped) against the handler's LV / RU / EN synonyms; every target at most once; unmapped columns are ignored.
 * Required targets block the next step until mapped. A mapping is remembered per building + kind + header signature
 * (import_mapping_presets) and re-applied when the same sheet layout comes back. Headers are not personal data.
 */
final class ColumnMapper
{
    private const LATVIAN = [
        'ā' => 'a', 'č' => 'c', 'ē' => 'e', 'ģ' => 'g', 'ī' => 'i', 'ķ' => 'k', 'ļ' => 'l', 'ņ' => 'n', 'š' => 's',
        'ū' => 'u', 'ž' => 'z', 'ō' => 'o', 'ŗ' => 'r', 'ё' => 'е',
    ];

    public static function normalise(string $header): string
    {
        $value = mb_strtolower(trim($header), 'UTF-8');
        $value = strtr($value, self::LATVIAN);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Suggested mapping: column index => target. Exact synonym matches first, then headers that start with a
     * multi-letter synonym ("Dzīvokļa Nr. (pilns)" → apartment_number), each target once.
     *
     * @param  list<string>  $header
     * @return array<int, string>
     */
    public function suggest(array $header, ImportHandler $handler): array
    {
        $synonyms = $this->synonyms($handler);
        $mapping = [];
        $used = [];

        foreach ([true, false] as $exact) {
            foreach ($header as $i => $cell) {
                if (isset($mapping[$i])) {
                    continue;
                }
                $norm = self::normalise($cell);
                if ($norm === '') {
                    continue;
                }
                foreach ($synonyms as $target => $list) {
                    if (isset($used[$target])) {
                        continue;
                    }
                    foreach ($list as $synonym) {
                        $hit = $exact ? $norm === $synonym : (mb_strlen($synonym) >= 4 && str_starts_with($norm, $synonym.' '));
                        if ($hit) {
                            $mapping[$i] = $target;
                            $used[$target] = true;

                            continue 3;
                        }
                    }
                }
            }
        }

        ksort($mapping);

        return $mapping;
    }

    /**
     * "Pirmā rinda ir virsraksti": at least half of the non-empty first-row cells match a synonym.
     *
     * @param  list<string>  $row
     */
    public function looksLikeHeader(array $row, ImportHandler $handler): bool
    {
        $cells = array_values(array_filter($row, static fn (string $c): bool => trim($c) !== ''));

        return $cells !== [] && count($this->suggest($cells, $handler)) * 2 >= count($cells);
    }

    /**
     * Problems of a chosen mapping: missing required targets and unknown targets (keys `required_missing:<target>`,
     * `unknown_target`). Duplicates are impossible by construction (the page keeps one column per target).
     *
     * @param  array<int|string, string>  $mapping
     * @return list<string>
     */
    public function problems(array $mapping, ImportHandler $handler): array
    {
        $targets = $handler->targets();
        $problems = [];
        $mapped = array_values(array_filter($mapping, static fn ($t): bool => is_string($t) && $t !== ''));

        foreach ($mapped as $target) {
            if (! array_key_exists($target, $targets)) {
                $problems[] = 'unknown_target';
            }
        }
        if (count($mapped) !== count(array_unique($mapped))) {
            $problems[] = 'duplicate_target';
        }
        foreach ($targets as $target => $spec) {
            if (($spec['required'] ?? false) && ! in_array($target, $mapped, true)) {
                $problems[] = 'required_missing:'.$target;
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * sha256 of the normalised header list (raw 32 bytes).
     *
     * @param  list<string>  $header
     */
    public static function signature(array $header): string
    {
        return hash('sha256', implode("\x1F", array_map(self::normalise(...), $header)), true);
    }

    /**
     * The remembered mapping for this header layout, or null.
     *
     * @param  list<string>  $header
     * @return array<int, string>|null
     */
    public function preset(ImportKind $kind, array $header): ?array
    {
        if ($header === []) {
            return null;
        }

        $preset = ImportMappingPreset::query()->where('kind', $kind->value)
            ->where('header_signature', Bytea::literal(self::signature($header)))->first();

        if ($preset === null) {
            return null;
        }

        $out = [];
        foreach ($preset->mapping as $index => $target) {
            if ((int) $index < count($header)) {
                $out[(int) $index] = (string) $target;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $header
     * @param  array<int, string>  $mapping
     */
    public function remember(ImportKind $kind, array $header, array $mapping, User $user): void
    {
        if ($header === []) {
            return;
        }

        $signature = Bytea::literal(self::signature($header));
        $mapping = array_filter($mapping, static fn ($t): bool => is_string($t) && $t !== '');

        ImportMappingPreset::query()->upsert([[
            'building_id' => CurrentBuilding::id(),
            'kind' => $kind->value,
            'header_signature' => $signature,
            'mapping' => json_encode((object) $mapping, JSON_THROW_ON_ERROR),
            'updated_by' => $user->id,
            'updated_at' => now(),
        ]], ['building_id', 'kind', 'header_signature'], ['mapping', 'updated_by', 'updated_at']);
    }

    /**
     * @return array<string, list<string>> target => normalised synonyms
     */
    private function synonyms(ImportHandler $handler): array
    {
        $out = [];
        foreach ($handler->targets() as $target => $spec) {
            $out[$target] = array_values(array_unique(array_map(self::normalise(...), [$target, ...($spec['synonyms'] ?? [])])));
        }

        return $out;
    }
}

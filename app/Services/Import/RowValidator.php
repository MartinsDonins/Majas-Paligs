<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Apartment;
use App\Tenancy\CurrentBuilding;

/**
 * Row validation of the import pipeline (DB-003, ROUND2-SPEC §4.5) — codes, never values. Two phases:
 *
 * 1. check(): file-only rules shared by every kind — `required_missing`, `too_long`, `apartment_not_found`,
 *    `apartment_merged` (warning: a retired number maps to the primary apartment) and `duplicate_in_file`
 *    (ImportHandler::dedupeKeys over the whole file, in file order).
 * 2. validateBatch(): the handler's database checks for a batch (ImportHandler::prepare + validate), then the final
 *    status / action per row. The dry-run runs it over the whole file; CommitImport runs it again per chunk inside
 *    the advisory lock, so a commit never relies on a stale dry-run.
 */
final class RowValidator
{
    /** @var array<string, 'error'|'warning'|'skip'> */
    public const SHARED_CODES = [
        'required_missing' => 'error',
        'too_long' => 'error',
        'apartment_not_found' => 'error',
        'apartment_merged' => 'warning',
        'duplicate_in_file' => 'error',
        'exists_skip' => 'skip',
        'exists_update' => 'warning',
    ];

    /**
     * @return array<string, 'error'|'warning'|'skip'>
     */
    public static function severity(ImportHandler $handler): array
    {
        return array_merge(self::SHARED_CODES, $handler->codes());
    }

    public function context(string $mode): ImportContext
    {
        $apartments = [];
        $retired = [];

        foreach (Apartment::query()->active()->get(['id', 'number', 'section_id', 'retired_numbers']) as $a) {
            $apartments[(int) $a->number] = ['id' => (int) $a->id, 'section_id' => (int) $a->section_id];
            foreach ($a->retired_numbers as $old) {
                $retired[(int) $old] = ['id' => (int) $a->id, 'section_id' => (int) $a->section_id, 'number' => (int) $a->number];
            }
        }

        return new ImportContext(CurrentBuilding::id(), in_array($mode, [ImportContext::CREATE_ONLY, ImportContext::CREATE_OR_UPDATE], true) ? $mode : ImportContext::CREATE_ONLY, $apartments, $retired);
    }

    /**
     * Both phases over every row (preview, dry-run).
     *
     * @param  array<int, list<string>>  $rows  file row number => cells
     * @param  array<string, string>  $mapping  column index => field key
     * @return list<RowResult>
     */
    public function validate(ImportHandler $handler, array $rows, array $mapping, ImportContext $context): array
    {
        $pairs = $this->check($handler, $rows, $mapping, $context);
        $this->validateBatch($handler, $context, $pairs);

        return array_map(static fn (array $p): RowResult => $p[1], $pairs);
    }

    /**
     * Phase 1 (no database except the apartment map).
     *
     * @param  array<int, list<string>>  $rows
     * @param  array<string, string>  $mapping
     * @return list<array{0: ImportRow, 1: RowResult}>
     */
    public function check(ImportHandler $handler, array $rows, array $mapping, ImportContext $context): array
    {
        $fields = $handler->fields();
        $pairs = [];

        foreach ($rows as $number => $cells) {
            $values = [];
            foreach ($mapping as $index => $field) {
                if (! isset($fields[$field])) {
                    continue;
                }
                $cell = (string) ($cells[(int) $index] ?? '');
                if ($fields[$field]->multi) {
                    $values[$field] = [...(array) ($values[$field] ?? []), $cell];
                } else {
                    $values[$field] = $cell;
                }
            }

            $row = new ImportRow((int) $number, $values);
            $result = new RowResult((int) $number);

            foreach ($fields as $key => $field) {
                if ($field->required && ! $row->has($key)) {
                    $result->add('required_missing');
                }
                foreach ($row->all($key) as $value) {
                    if (mb_strlen($value) > $field->maxLength) {
                        $result->add('too_long');
                    }
                }
            }

            if (isset($fields['apartment_number']) && $row->has('apartment_number')) {
                $this->resolveApartment($row->get('apartment_number'), $result, $context);
            }

            if (! $this->hasError($handler, $result)) {
                foreach ($handler->dedupeKeys($row, $result) as $key) {
                    if (isset($context->seen[$key])) {
                        $result->add('duplicate_in_file');

                        break;
                    }
                }
                if (! $result->has('duplicate_in_file')) {
                    foreach ($handler->dedupeKeys($row, $result) as $key) {
                        $context->seen[$key] = $row->number;
                    }
                }
            }

            $pairs[] = [$row, $result];
        }

        return $pairs;
    }

    /**
     * Phase 2: the handler's database checks + final status / action.
     *
     * @param  list<array{0: ImportRow, 1: RowResult}>  $pairs
     */
    public function validateBatch(ImportHandler $handler, ImportContext $context, array $pairs): void
    {
        $context->cache = [];
        $clean = array_values(array_filter($pairs, fn (array $p): bool => ! $this->hasError($handler, $p[1])));
        $handler->prepare($context, array_map(static fn (array $p): RowResult => $p[1], $clean));

        $severity = self::severity($handler);
        foreach ($pairs as [$row, $result]) {
            if (! $this->hasError($handler, $result)) {
                $handler->validate($row, $result, $context);
            }
            $result->finalize($severity);
        }
    }

    /**
     * "12", "12.", "Dz. 12", "кв. 12" → 12; anything else is not an apartment number.
     */
    public static function apartmentNumber(string $value): ?int
    {
        if (preg_match('/^\D{0,8}?(\d{1,4})\s*\.?$/u', trim($value), $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    private function resolveApartment(string $value, RowResult $result, ImportContext $context): void
    {
        $number = self::apartmentNumber($value);

        if ($number !== null && isset($context->apartments[$number])) {
            $result->apartmentId = $context->apartments[$number]['id'];
            $result->sectionId = $context->apartments[$number]['section_id'];
            $result->apartmentNumber = $number;

            return;
        }

        if ($number !== null && isset($context->retired[$number])) {
            $result->apartmentId = $context->retired[$number]['id'];
            $result->sectionId = $context->retired[$number]['section_id'];
            $result->apartmentNumber = $context->retired[$number]['number'];
            $result->add('apartment_merged');

            return;
        }

        $result->add('apartment_not_found');
    }

    private function hasError(ImportHandler $handler, RowResult $result): bool
    {
        $severity = self::severity($handler);

        foreach ($result->codes as $code) {
            if (($severity[$code] ?? 'error') === 'error') {
                return true;
            }
        }

        return false;
    }
}

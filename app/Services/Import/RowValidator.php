<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Apartment;
use App\Models\ImportRun;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Per-kind row validation (DB-003, ROUND2-SPEC §4.5). One validator instance validates one pass over a file, so it
 * can detect `duplicate_in_file` and cache database state; the pipeline runs it for the preview, the dry-run and —
 * inside each savepoint — again for the commit (state may have changed since the dry-run).
 *
 * Shared codes: apartment_not_found, apartment_merged (warning), required_missing, too_long, duplicate_in_file,
 * exists_skip (create_only), exists_update (create_or_update). Kind-specific codes live in the subclasses. Codes,
 * never values.
 */
abstract class RowValidator
{
    /** @var array<string, int> duplicate key => first line */
    private array $seen = [];

    /** @var array<int, Apartment>|null number => apartment */
    private ?array $apartments = null;

    /** @var array<int, Apartment>|null retired number => primary apartment */
    private ?array $retired = null;

    public function __construct(protected readonly ImportRun $run) {}

    /**
     * Validate one record against the file so far and the database. $cells are keyed by target (mapping applied,
     * unmapped columns dropped). Implementations call duplicate() with their duplicateKey().
     *
     * @param  array<string, string>  $cells
     */
    abstract public function validate(int $line, array $cells): RowResult;

    /**
     * The in-file identity of a record (pure — no database), or null when it has none. Two records with the same key
     * → the later one is `duplicate_in_file`.
     *
     * @param  array<string, string>  $cells
     */
    abstract public function duplicateKey(array $cells): ?string;

    /**
     * Commit runs in chunks: remember the keys of the records before the chunk without validating them again, so
     * `duplicate_in_file` gives the same answer as in the dry-run.
     *
     * @param  array<int, array<string, string>>  $earlier  line => cells
     */
    public function prime(array $earlier): void
    {
        foreach ($earlier as $line => $cells) {
            $key = $this->duplicateKey($cells);
            if ($key !== null && ! isset($this->seen[$key])) {
                $this->seen[$key] = (int) $line;
            }
        }
    }

    /**
     * Resolve an apartment number (current or retired → primary, `apartment_merged` warning).
     */
    protected function apartment(string $value, RowResult $result): ?Apartment
    {
        $number = preg_match('/^\D{0,6}?(\d{1,4})\D{0,4}$/u', trim($value), $m) === 1 ? (int) $m[1] : 0;

        if ($number <= 0) {
            $result->fail('apartment_not_found');

            return null;
        }

        $this->loadApartments();

        if (isset($this->apartments[$number])) {
            return $this->apartments[$number];
        }

        if (isset($this->retired[$number])) {
            $result->warn('apartment_merged');

            return $this->retired[$number];
        }

        $result->fail('apartment_not_found');

        return null;
    }

    /**
     * Required cell: empty → `required_missing`.
     *
     * @param  array<string, string>  $cells
     */
    protected function required(array $cells, string $target, RowResult $result): ?string
    {
        $value = trim($cells[$target] ?? '');

        if ($value === '') {
            $result->fail('required_missing');

            return null;
        }

        return $value;
    }

    /**
     * Length cap: longer → `too_long` (error).
     */
    protected function maxLength(?string $value, int $max, RowResult $result): ?string
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $result->fail('too_long');

            return null;
        }

        return $value;
    }

    /**
     * true when the key was already seen earlier in this pass (`duplicate_in_file` is the caller's code).
     */
    protected function duplicate(string $key, int $line): bool
    {
        if (isset($this->seen[$key]) && $this->seen[$key] !== $line) {
            return true;
        }

        $this->seen[$key] = $line;

        return false;
    }

    /**
     * Dates: dd.mm.yyyy, d.m.yy, yyyy-mm-dd, dd/mm/yyyy; Excel serial numbers are accepted with a warning
     * (`date_serial`). Returns null for empty, false for unparseable.
     */
    public static function date(string $value, ?RowResult $result = null): CarbonImmutable|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{5}$/', $value) === 1 && (int) $value > 20000 && (int) $value < 80000) {
            $result?->warn('date_serial');

            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'Europe/Riga')->addDays((int) $value);
        }

        foreach (['d.m.Y', 'j.n.Y', 'd.m.y', 'j.n.y', 'Y-m-d', 'd/m/Y', 'j/n/Y'] as $format) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$format, $value, 'Europe/Riga');
            } catch (Throwable) {
                $d = null;
            }
            if ($d instanceof CarbonImmutable && $d->format($format) === $value && $d->year >= 1990 && $d->year <= 2100) {
                return $d;
            }
        }

        return false;
    }

    /**
     * Yes / no (LV, RU, EN, ticks): true | false | null (empty = unknown) | 'unparsed'.
     */
    public static function yesNo(string $value): bool|string|null
    {
        $v = mb_strtolower(trim($value), 'UTF-8');

        return match (true) {
            $v === '' => null,
            in_array($v, ['jā', 'ja', 'yes', 'y', '1', 'x', '+', '✓', '✔', 'да', 'true'], true) => true,
            in_array($v, ['nē', 'ne', 'no', 'n', '0', 'нет', 'false'], true) => false,
            default => 'unparsed',
        };
    }

    private function loadApartments(): void
    {
        if ($this->apartments !== null) {
            return;
        }

        $this->apartments = [];
        $this->retired = [];

        foreach (Apartment::query()->active()->get(['id', 'building_id', 'section_id', 'number', 'retired_numbers']) as $apartment) {
            $this->apartments[(int) $apartment->number] = $apartment;
            foreach ($apartment->retired_numbers ?? [] as $old) {
                $this->retired[(int) $old] = $apartment;
            }
        }
    }
}

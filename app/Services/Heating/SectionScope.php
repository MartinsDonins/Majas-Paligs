<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Models\Section;
use InvalidArgumentException;

/**
 * Validated `section_ids` (MVP-SPEC B.1 "section_ids integrity") for survey rounds and manager reports:
 * every id is a section of the CURRENT building, the list is non-empty, has no duplicates and is sorted.
 * `null` (= whole building) is allowed only where the caller says so (survey rounds, building_admin only);
 * manager reports always list their sections explicitly. The DB trigger assert_section_ids_in_building() checks
 * the same rules again.
 */
final readonly class SectionScope
{
    /**
     * @param  list<int>|null  $ids  sorted section ids or null (whole building)
     */
    private function __construct(public ?array $ids) {}

    /**
     * @param  array<int|string, mixed>|null  $ids
     */
    public static function fromIds(?array $ids, bool $allowWholeBuilding = false): self
    {
        if ($ids === null) {
            if (! $allowWholeBuilding) {
                throw new InvalidArgumentException('section_ids are required.');
            }

            return new self(null);
        }

        $clean = [];
        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw new InvalidArgumentException('section_ids must be integers.');
            }
            $clean[] = (int) $id;
        }

        if ($clean === []) {
            throw new InvalidArgumentException('section_ids must not be empty.');
        }

        if (count(array_unique($clean)) !== count($clean)) {
            throw new InvalidArgumentException('section_ids must not contain duplicates.');
        }

        $known = Section::query()->whereIn('id', $clean)->pluck('id')->map(static fn ($v): int => (int) $v)->all();
        if (count($known) !== count($clean)) {
            throw new InvalidArgumentException('section_ids contain a section outside this building.');
        }

        sort($clean);

        return new self($clean);
    }

    /**
     * Every section of the current building, listed explicitly.
     */
    public static function wholeBuilding(): self
    {
        $ids = Section::query()->orderBy('id')->pluck('id')->map(static fn ($v): int => (int) $v)->values()->all();

        return self::fromIds($ids);
    }

    public function isWholeBuilding(): bool
    {
        return $this->ids === null;
    }

    /**
     * @param  list<int>  $allowed
     */
    public function isSubsetOf(array $allowed): bool
    {
        return $this->ids !== null && array_diff($this->ids, $allowed) === [];
    }

    /**
     * Explicit ids (whole building expanded).
     *
     * @return list<int>
     */
    public function resolvedIds(): array
    {
        return $this->ids ?? self::wholeBuilding()->ids ?? [];
    }
}

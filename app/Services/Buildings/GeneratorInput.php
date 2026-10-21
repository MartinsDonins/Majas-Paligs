<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use App\Enums\NumberingDirection;
use InvalidArgumentException;

/**
 * Generator wizard input: stairwells → floors → first apartment no. → numbering direction → layout preset.
 * When a section's first apartment number is null it continues after the previous section.
 */
final readonly class GeneratorInput
{
    /**
     * @param  list<array{number: int, floors: int, layout_code: string, first_apartment_no: ?int}>  $sections
     */
    public function __construct(
        public array $sections,
        public int $firstApartmentNo = 1,
        public NumberingDirection $direction = NumberingDirection::Ltr,
    ) {
        if ($sections === []) {
            throw new InvalidArgumentException(__('admin.generator.err_sections'));
        }

        $numbers = array_map(static fn (array $s): int => (int) $s['number'], $sections);
        if (count($numbers) !== count(array_unique($numbers))) {
            throw new InvalidArgumentException(__('admin.generator.err_sections'));
        }

        foreach ($sections as $s) {
            if ((int) $s['floors'] < 1 || (int) $s['floors'] > 30) {
                throw new InvalidArgumentException(__('admin.generator.err_floors'));
            }
        }

        if ($firstApartmentNo < 1) {
            throw new InvalidArgumentException(__('admin.generator.err_first_no'));
        }
    }

    /**
     * Uniform building: N sections × floors, same layout everywhere.
     */
    public static function uniform(int $sections, int $floors, string $layoutCode, int $firstApartmentNo = 1, NumberingDirection $direction = NumberingDirection::Ltr): self
    {
        $list = [];
        for ($i = 1; $i <= $sections; $i++) {
            $list[] = ['number' => $i, 'floors' => $floors, 'layout_code' => $layoutCode, 'first_apartment_no' => null];
        }

        return new self($list, $firstApartmentNo, $direction);
    }

    /**
     * @param  array<int, string>  $layoutBySection  section number => layout code
     */
    public static function perSection(int $floors, array $layoutBySection, int $firstApartmentNo = 1, NumberingDirection $direction = NumberingDirection::Ltr): self
    {
        ksort($layoutBySection);
        $list = [];
        foreach ($layoutBySection as $number => $code) {
            $list[] = ['number' => (int) $number, 'floors' => $floors, 'layout_code' => $code, 'first_apartment_no' => null];
        }

        return new self($list, $firstApartmentNo, $direction);
    }
}

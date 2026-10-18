<?php

declare(strict_types=1);

use App\Services\Parking\PlateNormalizer;

/*
 * PARK-001.1 / MVP-SPEC C.8 — synthetic plates only (AB1234 = example).
 */
it('normalizes case, separators and Cyrillic look-alikes to the same plate (AC 2)', function (string $typed): void {
    expect(PlateNormalizer::normalize($typed))->toBe('AB1234');
})->with([
    'lowercase with space' => ['ab 1234'],
    'dash' => ['AB-1234'],
    'cyrillic' => ['АВ1234'],
    'cyrillic lowercase' => ['ав 1234'],
    'dots and nbsp' => ["A.B\u{00A0}12·34"],
    'surrounding spaces' => ['  AB1234  '],
]);

it('maps other Cyrillic look-alikes and Latvian diacritics', function (): void {
    expect(PlateNormalizer::normalize('ЕКМНОРСТХ'))->toBeNull() // 9 characters → too long
        ->and(PlateNormalizer::normalize('ОРСТХ1'))->toBe('OPCTX1')
        ->and(PlateNormalizer::normalize('ЕКМН12'))->toBe('EKMH12')
        ->and(PlateNormalizer::normalize('ŠĒ123'))->toBe('SE123');
});

it('accepts foreign and short formats within 1..8 characters', function (string $typed, string $expected): void {
    expect(PlateNormalizer::normalize($typed))->toBe($expected);
})->with([
    'LT style' => ['ABC 123', 'ABC123'],
    'DE style' => ['B-MW 1234', 'BMW1234'],
    'single char' => ['7', '7'],
    'eight chars' => ['AB123456', 'AB123456'],
]);

it('rejects empty, too long and non-plate input', function (?string $typed): void {
    expect(PlateNormalizer::normalize($typed))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'only separators' => [' - . '],
    'nine chars' => ['AB1234567'],
    'emoji' => ['AB12🚗'],
    'sql' => ["AB1'; DROP"],
]);

it('masks plates for staff lists and defaults the country to LV', function (): void {
    expect(PlateNormalizer::mask('AB1234'))->toBe('AB••34')
        ->and(PlateNormalizer::mask('ABC'))->toBe('•••')
        ->and(PlateNormalizer::country('lt'))->toBe('LT')
        ->and(PlateNormalizer::country('Latvia'))->toBe('LV')
        ->and(PlateNormalizer::country(null))->toBe('LV');
});

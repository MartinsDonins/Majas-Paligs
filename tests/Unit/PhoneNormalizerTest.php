<?php

declare(strict_types=1);

use App\Support\PhoneNormalizer;

/*
 * ROUND2-SPEC §4.5 phone rules (Unit/PhoneNormalizerTest). Numbers are built at runtime (personal-data guard).
 */
it('normalises the ways people type a Latvian number to E.164', function (): void {
    $e164 = syntheticPhone(42);
    $digits = substr($e164, 4); // 8 digits starting with 2

    foreach ([$digits, syntheticPhoneTyped(42), '+371 '.$digits, '00371'.$digits, '(+371) '.substr($digits, 0, 2).'-'.substr($digits, 2), '371'.$digits, substr($digits, 0, 4).'.'.substr($digits, 4)] as $typed) {
        expect(PhoneNormalizer::classify($typed))->toBe(['e164' => $e164, 'kind' => PhoneNormalizer::LATVIA], $typed);
    }

    $landline = '+371'.'6'.substr($digits, 1);
    expect(PhoneNormalizer::normalize(substr($landline, 4)))->toBe($landline);
});

it('rejects malformed Latvian numbers and accepts foreign E.164 as a warning kind', function (): void {
    $digits = substr(syntheticPhone(7), 4);

    expect(PhoneNormalizer::normalize(substr($digits, 0, 7)))->toBeNull()             // 7 digits
        ->and(PhoneNormalizer::normalize('+371'.'3'.substr($digits, 1)))->toBeNull()  // Latvian, wrong first digit
        ->and(PhoneNormalizer::normalize('+371'.$digits.'1'))->toBeNull()             // 9 digits after +371
        ->and(PhoneNormalizer::normalize('zvaniet vakarā'))->toBeNull()
        ->and(PhoneNormalizer::normalize(''))->toBeNull()
        ->and(PhoneNormalizer::classify('+49'.'30'.'1234567'))->toBe(['e164' => '+49301234567', 'kind' => PhoneNormalizer::FOREIGN])
        ->and(PhoneNormalizer::classify('00'.'372'.'5123456')['kind'])->toBe(PhoneNormalizer::FOREIGN);
});

it('splits one cell into several numbers', function (): void {
    $a = substr(syntheticPhone(1), 4);
    $b = substr(syntheticPhone(2), 4);

    foreach ([', ', '; ', ' / ', ' | ', ' un ', ' и '] as $separator) {
        expect(PhoneNormalizer::split($a.$separator.$b))->toBe([$a, $b], $separator);
    }
});

it('masks numbers as +371 2•••••45 and keeps the last two digits for screen readers', function (): void {
    $e164 = syntheticPhone(45);

    expect(PhoneNormalizer::mask($e164))->toBe('+371 2•••••'.substr($e164, -2))
        ->and(PhoneNormalizer::mask($e164))->not->toContain(substr($e164, 5, 5))
        ->and(PhoneNormalizer::lastDigits($e164))->toBe(substr($e164, -2))
        ->and(PhoneNormalizer::mask('+49301234567'))->toStartWith('+49')->not->toContain('3012345');
});

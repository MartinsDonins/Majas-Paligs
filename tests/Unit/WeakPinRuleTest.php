<?php

declare(strict_types=1);

use App\Auth\WeakPinRule;

it('rejects weak PINs per deny class', function (string $pin, string $class, ?int $apartment = null): void {
    expect((new WeakPinRule($apartment))->weakReason($pin))->toBe($class);
})->with([
    'all same' => ['777777', 'same_digits'],
    'ascending' => ['234567', 'sequence'],
    'descending' => ['654321', 'sequence'],
    'ascending with wrap' => ['890123', 'sequence'],
    'descending with wrap' => ['210987', 'sequence'],
    'repeated pair' => ['474747', 'repeated_pattern'],
    'repeated triple' => ['839839', 'repeated_pattern'],
    'year first 4' => ['198754', 'year'],
    'year last 4' => ['571984', 'year'],
    'DDMMYY date' => ['240386', 'date'],
    'YYMMDD date' => ['860324', 'date'],
    'apartment repeated' => ['157157', 'repeated_pattern', 157],
    'apartment prefix' => ['145145', 'repeated_pattern', 145],
    'common list' => ['159753', 'common'],
]);

it('rejects the apartment number repeated even when no other rule matches', function (): void {
    expect((new WeakPinRule(47))->weakReason('474747'))->not->toBeNull();
});

it('accepts reasonable PINs', function (string $pin): void {
    expect((new WeakPinRule(145))->weakReason($pin))->toBeNull();
})->with(['958372', '384716', '603928', '847261']);

it('fails validation with the calm message for weak or malformed PINs', function (string $pin, string $message): void {
    $error = null;
    (new WeakPinRule)->validate('pin', $pin, function (string $m) use (&$error): void {
        $error = $m;
    });

    expect($error)->toBe(__($message));
})->with([
    ['123456', 'auth.pin.weak'],
    ['12345', 'auth.pin.format'],
    ['12a456', 'auth.pin.format'],
]);

it('ships a bundled list of 200 common PINs', function (): void {
    $lines = array_filter(file(resource_path('data/common-pins.txt'), FILE_IGNORE_NEW_LINES) ?: []);

    expect($lines)->toHaveCount(200)->and(array_unique($lines))->toHaveCount(200);
});

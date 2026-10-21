<?php

declare(strict_types=1);

use App\Enums\ImportKind;
use App\Enums\PermitStatus;
use App\Enums\PermitType;
use App\Models\ImportRun;
use App\Services\Import\RowResult;
use App\Services\Import\RowValidator;
use App\Services\Parking\PermitRowValidator;
use App\Services\Parking\PlateVault;

/*
 * ROUND2-SPEC §12 Unit/RowValidatorPermitTest (§4.5): permit row codes — never values. Dates, yes/no, merged
 * apartments, plates, duplicates and the commit-time priming.
 */
beforeEach(function (): void {
    $this->b = parkingHouse('validator-house', level: 1);
    inBuilding($this->b, function (): void {
        apartment($this->b, 5)->forceFill(['retired_numbers' => [105]])->save();
    });
});

function permitValidator(string $mode = 'create_only'): PermitRowValidator
{
    $run = new ImportRun(['kind' => ImportKind::Permits, 'options' => ['mode' => $mode]]);

    return new PermitRowValidator($run, app(PlateVault::class));
}

/**
 * @param  array<string, string>  $cells
 */
function permitRow(array $cells, ?PermitRowValidator $v = null, int $line = 2): RowResult
{
    return ($v ?? permitValidator())->validate($line, $cells + ['permit_number' => 'P-1', 'type' => 'pamata', 'apartment_number' => '1', 'plate' => 'AB1234']);
}

it('accepts a good main row and normalises its values', function (): void {
    inBuilding($this->b, function (): void {
        $r = permitRow(['permit_number' => ' p-1 ', 'type' => 'Pamata', 'apartment_number' => 'Dz. 1', 'plate' => 'ab 1234', 'valid_from' => '1.10.26', 'valid_to' => '2027-09-30', 'status' => 'aktīva', 'company_car' => 'jā']);

        expect($r->status())->toBe(RowResult::OK)->and($r->action)->toBe('create')
            ->and($r->values['number'])->toBe('P-1')
            ->and($r->values['type'])->toBe(PermitType::Main)
            ->and($r->values['plate'])->toBe('AB1234')
            ->and($r->values['valid_from']->toDateString())->toBe('2026-10-01')
            ->and($r->values['valid_to']->toDateString())->toBe('2027-09-30')
            ->and($r->values['status'])->toBe(PermitStatus::Active)
            ->and($r->values['company_car'])->toBeTrue();
    });
});

it('returns codes for bad rows', function (array $cells, array $codes, string $status): void {
    inBuilding($this->b, function () use ($cells, $codes, $status): void {
        $r = permitRow($cells);
        expect($r->uniqueCodes())->toBe($codes)->and($r->status())->toBe($status);
    });
})->with([
    'missing number' => [['permit_number' => ''], ['required_missing'], 'error'],
    'long number' => [['permit_number' => str_repeat('X', 33)], ['too_long'], 'error'],
    'unknown type' => [['type' => 'zelta'], ['type_unknown'], 'error'],
    'unknown apartment' => [['apartment_number' => '999'], ['apartment_not_found'], 'error'],
    'merged apartment' => [['apartment_number' => '105'], ['apartment_merged'], 'warning'],
    'main without plate' => [['plate' => ''], ['main_without_plate'], 'error'],
    'bad plate (main)' => [['plate' => 'AB-12345678'], ['plate_invalid'], 'error'],
    'bad plate (guest)' => [['type' => 'viesu', 'plate' => '###'], ['plate_invalid'], 'warning'],
    'bad date' => [['valid_from' => '31.02.2026'], ['date_invalid'], 'error'],
    'period' => [['valid_from' => '01.10.2026', 'valid_to' => '01.09.2026'], ['period_invalid'], 'error'],
    'status' => [['status' => 'varbūt'], ['status_unknown'], 'error'],
    'flag' => [['company_car' => 'varbūt'], ['flag_unparsed'], 'warning'],
    'excel serial date' => [['valid_from' => '46296'], ['date_serial'], 'warning'],
    'inactive new permit' => [['status' => 'nodota'], ['inactive_skip'], 'warning'],
]);

it('detects duplicates and in-file plate conflicts, also across commit chunks (prime)', function (): void {
    inBuilding($this->b, function (): void {
        $v = permitValidator();
        expect(permitRow([], $v, 2)->status())->toBe(RowResult::OK)
            ->and(permitRow([], $v, 3)->uniqueCodes())->toBe(['duplicate_in_file'])
            ->and(permitRow(['permit_number' => 'P-2', 'apartment_number' => '2'], $v, 4)->uniqueCodes())->toBe(['plate_conflict'])
            ->and(permitRow(['permit_number' => 'P-3', 'apartment_number' => '1'], $v, 5)->status())->toBe(RowResult::OK);

        // A later chunk validator primed with the earlier records answers the same.
        $later = permitValidator();
        $later->prime([2 => ['permit_number' => 'P-1', 'type' => 'pamata', 'apartment_number' => '1', 'plate' => 'AB1234']]);
        expect(permitRow([], $later, 9)->uniqueCodes())->toBe(['duplicate_in_file'])
            ->and(permitRow(['permit_number' => 'P-9', 'apartment_number' => '7'], $later, 10)->uniqueCodes())->toBe(['plate_conflict']);
    });
});

it('parses dates and yes/no like the spec', function (): void {
    expect(RowValidator::date('05.03.2026')->toDateString())->toBe('2026-03-05')
        ->and(RowValidator::date('5.3.26')->toDateString())->toBe('2026-03-05')
        ->and(RowValidator::date('2026-03-05')->toDateString())->toBe('2026-03-05')
        ->and(RowValidator::date('46086')->toDateString())->toBe('2026-03-05')
        ->and(RowValidator::date(''))->toBeNull()
        ->and(RowValidator::date('rīt'))->toBeFalse()
        ->and(RowValidator::yesNo('Jā'))->toBeTrue()->and(RowValidator::yesNo('x'))->toBeTrue()->and(RowValidator::yesNo('да'))->toBeTrue()
        ->and(RowValidator::yesNo('nē'))->toBeFalse()->and(RowValidator::yesNo('нет'))->toBeFalse()
        ->and(RowValidator::yesNo(''))->toBeNull()->and(RowValidator::yesNo('?'))->toBe('unparsed')
        ->and(PermitRowValidator::type('основной'))->toBe(PermitType::Main)
        ->and(PermitRowValidator::type('Viesu'))->toBe(PermitType::Guest)
        ->and(PermitRowValidator::status('Pazaudēta'))->toBe(PermitStatus::Lost);
});

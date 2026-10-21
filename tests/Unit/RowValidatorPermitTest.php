<?php

declare(strict_types=1);

use App\Enums\PermitStatus;
use App\Enums\PermitType;
use App\Services\Import\ImportContext;
use App\Services\Import\RowResult;
use App\Services\Import\RowValidator;
use App\Services\Parking\PermitImportHandler;
use App\Services\Parking\PermitRowValidator;

/*
 * ROUND2-SPEC §12 Unit/RowValidatorPermitTest (§4.5): permit row codes on the shared pipeline — never values. Dates,
 * yes/no, merged apartments, plates, duplicates and in-file plate conflicts.
 */
beforeEach(function (): void {
    $this->b = parkingHouse('validator-house', level: 1);
    inBuilding($this->b, function (): void {
        apartment($this->b, 5)->forceFill(['retired_numbers' => [105]])->save();
    });
});

/**
 * Validate permit rows (cells keyed by field) through both pipeline phases; returns the results in order.
 *
 * @param  list<array<string, string>>  $rows
 * @return list<RowResult>
 */
function permitRows(array $rows, string $mode = ImportContext::CREATE_ONLY): array
{
    $handler = app(PermitImportHandler::class);
    $fields = array_keys($handler->fields());
    $mapping = [];
    foreach ($fields as $i => $field) {
        $mapping[(string) $i] = $field;
    }
    $default = ['permit_number' => 'P-1', 'type' => 'pamata', 'apartment_number' => '1', 'plate' => 'AB1234'];
    $table = [];
    foreach ($rows as $n => $cells) {
        $cells += $default;
        $table[$n + 2] = array_map(static fn (string $f): string => $cells[$f] ?? '', $fields);
    }
    $validator = app(RowValidator::class);

    return $validator->validate($handler, $table, $mapping, $validator->context($mode));
}

function permitRow(array $cells): RowResult
{
    return permitRows([$cells])[0];
}

function permitStatus(RowResult $r): string
{
    return $r->status(RowValidator::severity(app(PermitImportHandler::class)));
}

it('accepts a good main row and normalises its values', function (): void {
    inBuilding($this->b, function (): void {
        $r = permitRow(['permit_number' => ' p-1 ', 'type' => 'Pamata', 'apartment_number' => 'Dz. 1', 'plate' => 'ab 1234', 'valid_from' => '1.10.26', 'valid_to' => '2027-09-30', 'status' => 'aktīva', 'company_car' => 'jā']);

        expect(permitStatus($r))->toBe(RowResult::OK)->and($r->action)->toBe('create')
            ->and($r->values['number'])->toBe('P-1')
            ->and($r->values['type'])->toBe('main')
            ->and($r->values['plate'])->toBe('AB1234')
            ->and($r->values['valid_from'])->toBe('2026-10-01')
            ->and($r->values['valid_to'])->toBe('2027-09-30')
            ->and($r->values['status'])->toBe('active')
            ->and($r->values['company_car'])->toBeTrue()
            ->and($r->preview['plate'])->toBe('AB••34');
    });
});

it('returns codes for bad rows', function (array $cells, array $codes, string $status): void {
    inBuilding($this->b, function () use ($cells, $codes, $status): void {
        $r = permitRow($cells);
        expect($r->codes)->toBe($codes)->and(permitStatus($r))->toBe($status);
    });
})->with([
    'missing number' => [['permit_number' => ''], ['required_missing'], 'error'],
    'long number' => [['permit_number' => str_repeat('X', 33)], ['too_long'], 'error'],
    'unknown type' => [['type' => 'zelta'], ['type_unknown'], 'error'],
    'unknown apartment' => [['apartment_number' => '999'], ['apartment_not_found'], 'error'],
    'merged apartment' => [['apartment_number' => '105'], ['apartment_merged'], 'warning'],
    'main without plate' => [['plate' => ''], ['main_without_plate'], 'error'],
    'bad plate (main)' => [['plate' => 'AB-12345678'], ['plate_invalid'], 'error'],
    'bad plate (guest)' => [['type' => 'viesu', 'plate' => '###'], ['plate_invalid_guest'], 'warning'],
    'bad date' => [['valid_from' => '31.02.2026'], ['date_invalid'], 'error'],
    'period' => [['valid_from' => '01.10.2026', 'valid_to' => '01.09.2026'], ['period_invalid'], 'error'],
    'status' => [['status' => 'varbūt'], ['status_unknown'], 'error'],
    'flag' => [['company_car' => 'varbūt'], ['flag_unparsed'], 'warning'],
    'excel serial date' => [['valid_from' => '46296'], ['date_serial'], 'warning'],
    'inactive new permit' => [['status' => 'nodota'], ['inactive_skip'], 'warning'],
]);

it('detects duplicates and in-file plate conflicts', function (): void {
    inBuilding($this->b, function (): void {
        [$a, $b, $c, $d] = permitRows([
            [],
            [],
            ['permit_number' => 'P-2', 'apartment_number' => '2'],
            ['permit_number' => 'P-3', 'apartment_number' => '1'],
        ]);

        expect(permitStatus($a))->toBe(RowResult::OK)
            ->and($b->codes)->toBe(['duplicate_in_file'])
            ->and($c->codes)->toBe(['plate_conflict'])
            ->and(permitStatus($d))->toBe(RowResult::OK);
    });
});

it('parses dates and yes/no like the spec', function (): void {
    expect(PermitRowValidator::date('05.03.2026')->toDateString())->toBe('2026-03-05')
        ->and(PermitRowValidator::date('5.3.26')->toDateString())->toBe('2026-03-05')
        ->and(PermitRowValidator::date('2026-03-05')->toDateString())->toBe('2026-03-05')
        ->and(PermitRowValidator::date('46086')->toDateString())->toBe('2026-03-05')
        ->and(PermitRowValidator::date(''))->toBeNull()
        ->and(PermitRowValidator::date('rīt'))->toBeFalse()
        ->and(PermitRowValidator::yesNo('Jā'))->toBeTrue()->and(PermitRowValidator::yesNo('x'))->toBeTrue()->and(PermitRowValidator::yesNo('да'))->toBeTrue()
        ->and(PermitRowValidator::yesNo('nē'))->toBeFalse()->and(PermitRowValidator::yesNo('нет'))->toBeFalse()
        ->and(PermitRowValidator::yesNo(''))->toBeNull()->and(PermitRowValidator::yesNo('?'))->toBe('unparsed')
        ->and(PermitRowValidator::type('основной'))->toBe(PermitType::Main)
        ->and(PermitRowValidator::type('Viesu'))->toBe(PermitType::Guest)
        ->and(PermitRowValidator::status('Pazaudēta'))->toBe(PermitStatus::Lost);
});

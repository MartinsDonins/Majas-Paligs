<?php

declare(strict_types=1);

use App\Services\Import\ColumnMapper;
use App\Services\Import\CsvReader;
use App\Services\Import\ImportFailed;
use App\Services\Parking\PermitImportHandler;

/*
 * DB-003 building blocks as used by the permit importer (ROUND2-SPEC §4.3–4.4): CsvReader (BOM, UTF-8 /
 * Windows-1257 / Windows-1251, delimiter sniffing, RFC 4180 quoting incl. multi-line cells, cell normalisation, caps)
 * and ColumnMapper (LV / RU / EN synonyms, header detection, required targets, signatures).
 */
it('strips the BOM, sniffs ";" "," and TAB and keeps record numbers', function (): void {
    $reader = new CsvReader;

    $semi = $reader->read("\xEF\xBB\xBFa;b;c\n1;2;3\n\n4;5;6\n");
    expect($semi->encoding)->toBe('utf-8')->and($semi->delimiter)->toBe(';')
        ->and($semi->rows)->toBe([1 => ['a', 'b', 'c'], 2 => ['1', '2', '3'], 4 => ['4', '5', '6']]);

    expect($reader->read("a,b\n1,2\n")->delimiter)->toBe(',')
        ->and($reader->read("a\tb\n1\t2\n")->delimiter)->toBe('tab')
        ->and($reader->read("a;b\n\"1,5\";2\n")->delimiter)->toBe(';');
});

it('parses RFC 4180 quoting with escaped quotes and multi-line cells', function (): void {
    $t = (new CsvReader)->read("Nr;Piezīme\n1;\"viena; \"\"divas\"\"\nrinda\"\n2;'=SUM(A1)\n3; -\n");

    // Record numbers, not physical lines: the quoted line break stays inside record 2.
    expect($t->rows[2])->toBe(['1', 'viena; "divas" rinda'])
        ->and($t->rows[3])->toBe(['2', '=SUM(A1)'])
        ->and($t->rows[4])->toBe(['3']);
});

it('detects Windows-1257 and Windows-1251 and converts to UTF-8', function (): void {
    $lv = iconv('UTF-8', 'WINDOWS-1257', "Dzīvoklis;Vārds\n1;Ēriks Šķēle\n");
    $ru = iconv('UTF-8', 'WINDOWS-1251', "Квартира;Тип\n1;гостевой\n");

    $a = (new CsvReader)->read($lv);
    $b = (new CsvReader)->read($ru);

    expect($a->encoding)->toBe('windows-1257')->and($a->encodingGuessed)->toBeTrue()->and($a->rows[2][1])->toBe('Ēriks Šķēle')
        ->and($b->encoding)->toBe('windows-1251')->and($b->rows[1][0])->toBe('Квартира')
        ->and((new CsvReader)->read($lv, 'windows-1251')->encoding)->toBe('windows-1251');

    expect(fn () => (new CsvReader)->read($lv, 'utf-8'))->toThrow(ImportFailed::class, 'encoding');
});

it('enforces the column and cell caps', function (): void {
    expect(fn () => (new CsvReader)->read(implode(';', range(1, 31))."\n"))->toThrow(ImportFailed::class, 'too_many_columns')
        ->and(fn () => (new CsvReader)->read('a;'.str_repeat('x', 1001)."\n"))->toThrow(ImportFailed::class, 'cell_too_long');
});

it('maps LV / RU / EN headers to permit targets and detects the header row', function (): void {
    $handler = app(PermitImportHandler::class);
    $mapper = new ColumnMapper;

    expect($mapper->suggest(['Caurlaides Nr.', 'Veids', 'Dzīvoklis', 'Auto numurs', 'Derīga līdz', 'Piezīmes'], $handler))
        ->toBe([0 => 'permit_number', 1 => 'type', 2 => 'apartment_number', 3 => 'plate', 4 => 'valid_to'])
        ->and($mapper->suggest(['Номер пропуска', 'Тип', 'Кв', 'Госномер', 'Выдал'], $handler))
        ->toBe([0 => 'permit_number', 1 => 'type', 2 => 'apartment_number', 3 => 'plate', 4 => 'issued_by'])
        ->and($mapper->suggest(['Permit no', 'Type', 'Apt', 'Plate', 'Valid until', 'Company car'], $handler))
        ->toBe([0 => 'permit_number', 1 => 'type', 2 => 'apartment_number', 3 => 'plate', 4 => 'valid_to', 5 => 'company_car'])
        ->and($mapper->looksLikeHeader(['Nr', 'Veids', 'Dz.'], $handler))->toBeTrue()
        ->and($mapper->looksLikeHeader(['P-1', 'pamata', '12', 'AB1234'], $handler))->toBeFalse()
        ->and($mapper->problems([0 => 'permit_number', 1 => 'type'], $handler))->toBe(['required_missing:apartment_number'])
        ->and($mapper->problems([0 => 'permit_number', 1 => 'permit_number', 2 => 'type', 3 => 'apartment_number'], $handler))->toBe(['duplicate_target'])
        ->and(ColumnMapper::signature(['Nr', 'Veids']))->toBe(ColumnMapper::signature(['NR.', ' veids ']))
        ->and(strlen(ColumnMapper::signature(['Nr'])))->toBe(32);
});

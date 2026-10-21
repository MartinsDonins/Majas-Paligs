<?php

declare(strict_types=1);

use App\Services\Import\CsvReader;
use App\Services\Import\ImportRefused;

/*
 * ROUND2-SPEC §4.3 parsing rules (Unit/CsvReaderTest): BOM, encodings, delimiters, RFC 4180 quoting, multi-line
 * cells, cell normalisation and the caps. Synthetic content only.
 */
function reader(): CsvReader
{
    return new CsvReader;
}

it('reads an Excel LV "CSV UTF-8" export (BOM + semicolon)', function (): void {
    $t = reader()->parse("\xEF\xBB\xBFDzīvoklis;Vārds;Komentārs\r\n1;Jānis Bērziņš;Ņ, ļ, ž\r\n");

    expect($t->encoding)->toBe('utf-8')
        ->and($t->delimiter)->toBe(';')
        ->and($t->rows)->toBe([['Dzīvoklis', 'Vārds', 'Komentārs'], ['1', 'Jānis Bērziņš', 'Ņ, ļ, ž']]);
});

it('reads a Google Sheets export (comma, UTF-8, quoted cells with commas, quotes and line breaks)', function (): void {
    $t = reader()->parse("Dzīvoklis,Vārds,Komentārs\n2,\"Bērziņa, Anna\",\"Saka \"\"labdien\"\"\notrā rindā\"\n3,Ivans,\n");

    expect($t->delimiter)->toBe(',')
        ->and($t->rows[1])->toBe(['2', 'Bērziņa, Anna', 'Saka "labdien" otrā rindā'])
        ->and($t->rows[2])->toBe(['3', 'Ivans', ''])
        ->and($t->dataRows(true))->toHaveKeys([2, 3]);
});

it('detects Windows-1257 (Latvian) and Windows-1251 (Cyrillic) files and converts them to UTF-8 NFC', function (): void {
    $lv = (string) iconv('UTF-8', 'WINDOWS-1257', "Dzīvoklis;Vārds\n1;Jānis Ozoliņš\n2;Līga Šķēle\n");
    $ru = (string) iconv('UTF-8', 'WINDOWS-1251', "Квартира;Имя\n1;Иван Петров\n2;Ольга Смирнова\n");

    $tLv = reader()->parse($lv);
    $tRu = reader()->parse($ru);

    expect($tLv->encoding)->toBe('windows-1257')
        ->and($tLv->rows[2])->toBe(['2', 'Līga Šķēle'])
        ->and($tRu->encoding)->toBe('windows-1251')
        ->and($tRu->rows[0])->toBe(['Квартира', 'Имя'])
        ->and(Normalizer::isNormalized($tLv->rows[1][1], Normalizer::FORM_C))->toBeTrue();

    // A wrong guess can be overridden by the person (selector on the mapping step).
    expect(reader()->parse($lv, 'windows-1257', ';')->rows[1][1])->toBe('Jānis Ozoliņš');
});

it('normalises cells: trim, inner whitespace, Excel text prefix, empty markers; never evaluates formulas', function (): void {
    $t = reader()->parse("a;b;c;d;e;f\n  x   y  ;'0042;-;n/a;nav;=SUM(A1:A2)\n");

    expect($t->rows[1])->toBe(['x y', '0042', '', '', '', '=SUM(A1:A2)']);
});

it('sniffs TAB-separated pastes and keeps the detected delimiter key', function (): void {
    $t = reader()->parse("Dzīvoklis\tVārds\n1\tAnna\n2\tPēteris\n");

    expect($t->delimiter)->toBe('tab')->and($t->rows[2])->toBe(['2', 'Pēteris']);
});

it('refuses files over the caps with a code (never a value)', function (string $csv, string $code): void {
    try {
        reader()->parse($csv);
        $this->fail('expected ImportRefused');
    } catch (ImportRefused $e) {
        expect($e->key)->toBe($code)->and($e->getMessage())->not->toContain('Anna');
    }
})->with([
    'too many rows' => [str_repeat("1;Anna\n", 2002), 'too_many_rows'],
    'too many columns' => [implode(';', array_fill(0, 31, 'Anna'))."\n", 'too_many_columns'],
    'cell too long' => ['1;'.str_repeat('Anna', 260)."\n", 'cell_too_long'],
    'empty' => ["\xEF\xBB\xBF \n\n", 'file_empty'],
    'binary' => ["PK\x03\x04\0\0Anna", 'file_type'],
]);

it('refuses more than 2 MB', function (): void {
    expect(fn () => reader()->parse(str_repeat('a', 2 * 1024 * 1024 + 1)))->toThrow(ImportRefused::class);
});

it('checks the upload: extension and a text-like file type', function (): void {
    $dir = sys_get_temp_dir();
    $csv = tempnam($dir, 'mpc');
    file_put_contents($csv, "Dzīvoklis;Vārds\n1;Anna\n");

    reader()->assertUpload($csv, 'saraksts.csv');
    expect(fn () => reader()->assertUpload($csv, 'saraksts.xlsx'))->toThrow(ImportRefused::class);

    $png = tempnam($dir, 'mpp');
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABpfZFQAAAAABJRU5ErkJggg=='));
    expect(fn () => reader()->assertUpload($png, 'saraksts.csv'))->toThrow(ImportRefused::class);

    @unlink($csv);
    @unlink($png);
});

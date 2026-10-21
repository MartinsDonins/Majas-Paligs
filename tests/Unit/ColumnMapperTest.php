<?php

declare(strict_types=1);

use App\Services\Import\ColumnMapper;
use App\Services\Roster\RosterImportHandler;

/*
 * Unit/ColumnMapperTest (ROUND2-SPEC §4.4): LV / RU / EN synonyms after normalisation, header detection, problems,
 * preset signature.
 */
function rosterHandler(): RosterImportHandler
{
    return app(RosterImportHandler::class);
}

it('normalises headers (case, diacritics, punctuation)', function (): void {
    expect(ColumnMapper::normalize('  Dz. Nr. '))->toBe('dz nr')
        ->and(ColumnMapper::normalize('E-pasts'))->toBe('e pasts')
        ->and(ColumnMapper::normalize('Komentārs'))->toBe('komentars')
        ->and(ColumnMapper::normalize('Квартира №'))->toBe('квартира');
});

it('maps LV, RU and EN headers to the roster fields', function (string $header, ?string $field): void {
    expect((new ColumnMapper)->match(rosterHandler(), $header))->toBe($field);
})->with([
    ['Dzīvoklis', 'apartment_number'], ['Dz. nr.', 'apartment_number'], ['кв', 'apartment_number'], ['Apartment', 'apartment_number'],
    ['Vārds, uzvārds', 'name'], ['ФИО', 'name'], ['Name', 'name'],
    ['Telefons', 'phone'], ['Tālr.', 'phone'], ['Telefons 2', 'phone'], ['Телефон', 'phone'], ['Mobile', 'phone'], ['Mobilais telefons', 'phone'],
    ['E-pasts', 'email'], ['e-mail', 'email'], ['Почта', 'email'],
    ['Statuss', 'relation'], ['Статус', 'relation'],
    ['BIS', 'bis_registered'], ['БИС', 'bis_registered'],
    ['WhatsApp grupa', 'whatsapp_member'], ['WA', 'whatsapp_member'],
    ['Piezīmes', 'comment'], ['Комментарий', 'comment'], ['Notes', 'comment'],
    ['Stāvs', null], ['', null],
]);

it('suggests one column per field, several for the multi phone field', function (): void {
    $mapping = (new ColumnMapper)->suggest(rosterHandler(), ['Dzīvoklis', 'Vārds', 'Telefons 1', 'Telefons 2', 'Vārds', 'Stāvs']);

    expect($mapping)->toBe(['0' => 'apartment_number', '1' => 'name', '2' => 'phone', '3' => 'phone']);
});

it('detects a header row when at least half the cells are known headers', function (): void {
    $mapper = new ColumnMapper;

    expect($mapper->looksLikeHeader(rosterHandler(), ['Dzīvoklis', 'Vārds', 'Kaut kas']))->toBeTrue()
        ->and($mapper->looksLikeHeader(rosterHandler(), ['1', 'Anna', 'Paraugs']))->toBeFalse();
});

it('reports missing required and duplicated single fields', function (): void {
    $mapper = new ColumnMapper;

    expect($mapper->problems(rosterHandler(), ['1' => 'name'], 3))->toBe(['mapping_required'])
        ->and($mapper->problems(rosterHandler(), ['0' => 'apartment_number', '1' => 'name', '2' => 'name'], 3))->toBe(['mapping_duplicate'])
        ->and($mapper->problems(rosterHandler(), ['0' => 'apartment_number', '1' => 'phone', '2' => 'phone'], 3))->toBe([])
        ->and($mapper->problems(rosterHandler(), ['0' => 'apartment_number', '7' => 'name'], 3))->toBe(['mapping_invalid']);
});

it('signs a header layout independent of case and accents', function (): void {
    expect(ColumnMapper::signature(['Dzīvoklis', 'Vārds']))->toBe(ColumnMapper::signature(['DZIVOKLIS', 'vards']))
        ->and(ColumnMapper::signature(['Dzīvoklis', 'Vārds']))->not->toBe(ColumnMapper::signature(['Vārds', 'Dzīvoklis']))
        ->and(strlen(ColumnMapper::signature(['a'])))->toBe(32);
});

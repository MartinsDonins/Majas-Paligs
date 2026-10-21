<?php

declare(strict_types=1);

use App\Models\Apartment;
use App\Services\Import\ImportContext;
use App\Services\Import\RowResult;
use App\Services\Import\RowValidator;
use App\Services\Roster\RosterImportHandler;

/*
 * Unit/RowValidatorRosterTest (ROUND2-SPEC §4.5): roster codes — phone_invalid / phone_foreign / phone_too_many,
 * email_invalid, relation_unknown, flag_unparsed, comment_sensitive_hint, contact_empty — plus the shared apartment
 * and duplicate rules. Codes only, never values.
 */
const ROSTER_MAPPING = ['0' => 'apartment_number', '1' => 'name', '2' => 'phone', '3' => 'email', '4' => 'relation', '5' => 'bis_registered', '6' => 'whatsapp_member', '7' => 'comment'];

/**
 * @param  array<int, list<string>>  $rows
 * @return array<int, RowResult>
 */
function validateRoster($building, array $rows, string $mode = ImportContext::CREATE_ONLY): array
{
    return inBuilding($building, function () use ($rows, $mode): array {
        $validator = new RowValidator;
        $out = [];
        foreach ($validator->validate(app(RosterImportHandler::class), $rows, ROSTER_MAPPING, $validator->context($mode)) as $r) {
            $out[$r->number] = $r;
        }

        return $out;
    });
}

beforeEach(function (): void {
    $this->b = rosterHouse('rv-house');
});

it('classifies phones, e-mails, relations and yes/no flags', function (): void {
    $p = fn (int $n) => syntheticPhoneTyped($n);
    $r = validateRoster($this->b, [
        2 => ['1', 'A', $p(1), 'a@example.test', 'īpašnieks', 'jā', '✓', ''],
        3 => ['2', 'B', $p(2).' / '.$p(3).' / '.$p(4).' / '.$p(5), '', 'īrniece', 'ne', 'нет', ''],
        4 => ['3', 'C', '+44'.'20'.'79460000', '', 'vecmāmiņa', 'varbūt', '', ''],
        5 => ['4', 'D', '123', 'nav-epasta', '', '', '', ''],
        6 => ['5', 'E', '', '', '', '', '', 'Ir parāds par apkuri'],
        7 => ['6', '', '', '', '', '', '', ''],
        8 => ['7', 'F', '', '', 'Собственник', 'да', 'x', 'Больна, звонить детям'],
    ]);

    expect($r[2]->codes)->toBe([])->and($r[2]->status(RowValidator::severity(app(RosterImportHandler::class))))->toBe(RowResult::OK)
        ->and($r[2]->values['relation'])->toBe('owner')->and($r[2]->values['bis'])->toBeTrue()->and($r[2]->values['whatsapp'])->toBeTrue()
        ->and($r[3]->codes)->toBe(['phone_too_many'])->and($r[3]->values['phones'])->toHaveCount(3)->and($r[3]->values['relation'])->toBe('tenant')
        ->and($r[3]->values['bis'])->toBeFalse()->and($r[3]->values['whatsapp'])->toBeFalse()
        ->and($r[4]->codes)->toBe(['phone_foreign', 'relation_unknown', 'flag_unparsed'])->and($r[4]->values['relation'])->toBe('contact')
        ->and($r[4]->action)->toBe('create')
        ->and($r[5]->codes)->toBe(['phone_invalid', 'email_invalid'])->and($r[5]->action)->toBe('skip')
        ->and($r[6]->codes)->toBe(['comment_sensitive_hint'])->and($r[6]->action)->toBe('create')
        ->and($r[7]->codes)->toBe(['contact_empty'])
        ->and($r[8]->codes)->toBe(['comment_sensitive_hint'])->and($r[8]->values['relation'])->toBe('owner');
});

it('applies the shared rules: required, too long, apartment not found / merged, duplicates in the file', function (): void {
    // Merge apartments 1 + 2: number 2 is retired into 1.
    inBuilding($this->b, function (): void {
        $primary = Apartment::query()->where('number', 1)->firstOrFail();
        $secondary = Apartment::query()->where('number', 2)->firstOrFail();
        $primary->forceFill(['retired_numbers' => [2]])->save();
        $secondary->forceFill(['archived_at' => now()])->save();
    });

    $r = validateRoster($this->b, [
        2 => ['', 'Bez dzīvokļa', '', '', '', '', '', ''],
        3 => ['500', 'Nav tāda', '', '', '', '', '', ''],
        4 => ['Dz. 2', 'Apvienots', '', 'x@example.test', '', '', '', ''],
        5 => ['1', str_repeat('Garš', 40), '', '', '', '', '', ''],
        6 => ['1', 'Cits', '', 'X@example.test', '', '', '', ''],
        7 => ['кв. 3', 'Kirilica', '', '', '', '', '', ''],
    ]);

    expect($r[2]->codes)->toBe(['required_missing'])
        ->and($r[3]->codes)->toBe(['apartment_not_found'])
        ->and($r[4]->codes)->toBe(['apartment_merged'])->and($r[4]->apartmentNumber)->toBe(1)
        ->and($r[5]->codes)->toBe(['too_long'])
        ->and($r[6]->codes)->toBe(['duplicate_in_file'])
        ->and($r[7]->codes)->toBe([])->and($r[7]->apartmentNumber)->toBe(3);
});

it('matches existing contacts of the same apartment only (exists_skip / exists_update)', function (): void {
    $admin = rosterAdmin($this->b);
    $this->actingAs($admin);
    rosterContact($this->b, $admin, 1, 'Esošs', [syntheticPhone(1)]);
    rosterContact($this->b, $admin, 2, 'Tikai Vārds');

    $rows = [
        2 => ['1', 'Jauns vārds', syntheticPhoneTyped(1), '', '', '', '', ''],
        3 => ['3', 'Jauns vārds', syntheticPhoneTyped(1), '', '', '', '', ''],
        4 => ['2', 'tikai vārds', '', '', '', '', '', ''],
    ];

    $skip = validateRoster($this->b, $rows);
    $update = validateRoster($this->b, $rows, ImportContext::CREATE_OR_UPDATE);

    expect($skip[2]->codes)->toBe(['exists_skip'])->and($skip[2]->action)->toBe('skip')
        ->and($skip[3]->codes)->toBe([])->and($skip[3]->action)->toBe('create') // same phone, other apartment
        ->and($skip[4]->codes)->toBe(['exists_skip'])
        ->and($update[2]->codes)->toBe(['exists_update'])->and($update[2]->action)->toBe('update')->and($update[2]->targetId)->not->toBeNull();
});

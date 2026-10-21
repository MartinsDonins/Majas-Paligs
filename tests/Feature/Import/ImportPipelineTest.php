<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Casts\Bytea;
use App\Filament\Manage\Pages\RosterImportPage;
use App\Jobs\CommitImport;
use App\Models\AuditEntry;
use App\Models\ImportMappingPreset;
use App\Models\ImportRun;
use App\Models\ResidentContact;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportRefused;
use App\Services\Import\ImportRunService;
use App\Services\Import\RowResult;
use App\Services\Roster\RosterViewer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * DB-003 + AC-R2-11 (Feature/Import/ImportPipelineTest): stages, automatic mapping of Google Sheets / Excel LV /
 * Windows-1257 exports, masked preview, validation codes, dry-run = commit counts, re-validation on commit, idempotent
 * job, one commit per building at a time, same-file detection, payload deletion, presets, update mode, daily cap.
 */
beforeEach(function (): void {
    $this->b = rosterHouse('imp-house');
    $this->admin = rosterAdmin($this->b, 'imp-admin@example.test');
    $this->actingAs($this->admin);
    app(StepUpService::class)->markFresh($this->admin, 'totp');
});

function imports(): ImportRunService
{
    return app(ImportRunService::class);
}

/**
 * The AC-R2-11 sheet: 5 good rows, apartment 99 (not found), a bad phone, a duplicate e-mail in the same apartment.
 *
 * @return list<list<string>>
 */
function acSheet(): array
{
    return [
        ['1', 'Anna Paraudziņa', syntheticPhoneTyped(1), 'anna@example.test', 'Īpašniece', 'jā', 'jā', ''],
        ['2', 'Pēteris Paraugs', syntheticPhoneTyped(2).', '.syntheticPhoneTyped(3), '', 'īrnieks', 'nē', '', 'Zvanīt vakarā'],
        ['3', 'Иван Образцов', '', 'ivan@example.test', 'собственник', 'да', 'нет', ''],
        ['4', 'Līga Bez Telefona', '', '', '', '', '', ''],
        ['5', 'Jānis Ārzemnieks', '+49'.'30'.'1234567', '', 'kontaktpersona', '?', '', ''],
        ['99', 'Neesošs Dzīvoklis', syntheticPhoneTyped(9), '', '', '', '', ''],
        ['6', 'Slikts Telefons', '12-34', '', '', '', '', ''],
        ['1', 'Anna Dublikāts', '', 'ANNA@example.test', '', '', '', ''],
    ];
}

function codesOf(array $results): array
{
    $out = [];
    foreach ($results as $r) {
        $out[$r->number] = $r->codes;
    }

    return $out;
}

it('imports a Google Sheets export: automatic mapping, masked preview, codes, dry-run counts = committed counts', function (): void {
    $run = uploadCsv($this->b, $this->admin, rosterCsv(acSheet()));

    expect($run->status)->toBe(ImportRun::UPLOADED)
        ->and($run->delimiter)->toBe(',')
        ->and($run->encoding)->toBe('utf-8')
        ->and($run->has_header)->toBeTrue()
        ->and($run->row_count)->toBe(8)
        ->and($run->mapping)->toBe(['0' => 'apartment_number', '1' => 'name', '2' => 'phone', '3' => 'email', '4' => 'relation', '5' => 'bis_registered', '6' => 'whatsapp_member', '7' => 'comment']);

    inBuilding($this->b, fn () => imports()->map($this->admin, $run, $run->mapping, ImportContext::CREATE_ONLY));
    $preview = inBuilding($this->b, fn () => imports()->preview($this->admin, $run->refresh()));
    $codes = codesOf($preview['rows']);

    expect($preview['total'])->toBe(8)
        ->and($codes[2])->toBe([])
        ->and($codes[6])->toContain('phone_foreign')->toContain('flag_unparsed')
        ->and($codes[7])->toBe(['apartment_not_found'])
        ->and($codes[8])->toContain('phone_invalid')
        ->and($codes[9])->toBe(['duplicate_in_file']);

    // Masked preview cells: names shown, phones / e-mails masked.
    $first = $preview['rows'][0];
    expect($first->preview['name'])->toBe('Anna Paraudziņa')
        ->and($first->preview['phone'])->toBe('+371 2•••••'.substr(syntheticPhone(1), -2))
        ->and($first->preview['email'])->toBe('a•••@…')
        ->and(implode(' ', $first->preview))->not->toContain(substr(syntheticPhone(1), 4));

    $run = inBuilding($this->b, fn () => imports()->dryRun($this->admin, $run->refresh()));
    expect($run->status)->toBe(ImportRun::VALIDATED)
        ->and($run->summary['actions'])->toBe(['create' => 5, 'update' => 0, 'skip' => 3])
        ->and($run->summary['codes'])->toMatchArray(['apartment_not_found' => 1, 'phone_invalid' => 1, 'duplicate_in_file' => 1]);

    $run = inBuilding($this->b, fn () => imports()->commit($this->admin, $run, ['art14']));

    expect($run->status)->toBe(ImportRun::COMMITTED)
        ->and($run->payload_ciphertext)->toBeNull()
        ->and($run->committed_counts)->toEqual(['processed' => 8, 'created' => 5, 'updated' => 0, 'skipped' => 3])
        ->and(inBuilding($this->b, fn () => ResidentContact::query()->count()))->toBe(5)
        ->and(inBuilding($this->b, fn () => ResidentContact::query()->where('source', 'import')->where('import_run_id', $run->id)->count()))->toBe(5);

    $peteris = inBuilding($this->b, fn () => ResidentContact::query()->where('apartment_id', apartment($this->b, 2)->id)->firstOrFail());
    $plain = inBuilding($this->b, fn () => app(RosterViewer::class)->plain($peteris));
    expect($plain->phones)->toBe([syntheticPhone(2), syntheticPhone(3)])
        ->and($plain->relation->value)->toBe('tenant')
        ->and($plain->bisRegistered)->toBeFalse()
        ->and($plain->comment)->toBe('Zvanīt vakarā');

    $audit = inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'import.commit')->firstOrFail());
    expect($audit->data)->toEqual(['kind' => 'roster', 'rows' => 8, 'created' => 5, 'updated' => 0, 'skipped' => 3]);
});

it('maps an Excel LV export (semicolon + BOM) and its Windows-1257 variant automatically', function (): void {
    $rows = [['1', 'Jānis Ozoliņš', syntheticPhoneTyped(11), '', 'Īpašnieks', 'Jā', 'Nē', 'Šķūnis']];
    $utf8 = rosterCsv($rows, ';', bom: true);
    $win = (string) iconv('UTF-8', 'WINDOWS-1257', rosterCsv($rows, ';'));

    foreach ([$utf8, $win] as $i => $csv) {
        $run = uploadCsv($this->b, $this->admin, $csv);
        expect($run->delimiter)->toBe(';')
            ->and($run->encoding)->toBe($i === 0 ? 'utf-8' : 'windows-1257')
            ->and($run->mapping)->toHaveCount(8)
            ->and(inBuilding($this->b, fn () => imports()->headers($run))[1])->toBe('Vārds');

        inBuilding($this->b, fn () => imports()->map($this->admin, $run, $run->mapping, ImportContext::CREATE_OR_UPDATE));
        $preview = inBuilding($this->b, fn () => imports()->preview($this->admin, $run->refresh()));
        expect($preview['rows'][0]->preview['name'])->toBe('Jānis Ozoliņš');
        inBuilding($this->b, fn () => imports()->discard($this->admin, $run->refresh()));
    }

    // Russian headers map too.
    $ru = uploadCsv($this->b, $this->admin, rosterCsv([['1', 'Иван', '', '', '', '', '', '']], ';', header: ['Квартира', 'Имя', 'Телефон', 'E-mail', 'Статус', 'БИС', 'WhatsApp', 'Комментарий']));
    expect($ru->mapping)->toBe(['0' => 'apartment_number', '1' => 'name', '2' => 'phone', '3' => 'email', '4' => 'relation', '5' => 'bis_registered', '6' => 'whatsapp_member', '7' => 'comment']);
});

it('detects a second upload of the same file within 24 h', function (): void {
    $csv = rosterCsv([['1', 'Anna Paraudziņa', '', 'anna@example.test', '', '', '', '']]);
    $first = uploadCsv($this->b, $this->admin, $csv);
    inBuilding($this->b, fn () => imports()->map($this->admin, $first, $first->mapping, ImportContext::CREATE_ONLY));
    inBuilding($this->b, fn () => imports()->dryRun($this->admin, $first->refresh()));
    inBuilding($this->b, fn () => imports()->commit($this->admin, $first->refresh(), ['art14']));

    $second = uploadCsv($this->b, $this->admin, $csv);
    expect(inBuilding($this->b, fn () => imports()->previousImport($second))?->id)->toBe($first->id);

    // The re-import finds the contact: create_only skips it.
    inBuilding($this->b, fn () => imports()->map($this->admin, $second, $second->mapping, ImportContext::CREATE_ONLY));
    $run = inBuilding($this->b, fn () => imports()->dryRun($this->admin, $second->refresh()));
    expect($run->summary['actions'])->toBe(['create' => 0, 'update' => 0, 'skip' => 1])
        ->and($run->summary['codes'])->toBe(['exists_skip' => 1]);

    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);
    $other = uploadCsv($this->b, $this->admin, $csv);
    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(RosterImportPage::class)
        ->assertSet('runId', $other->id)
        ->assertSeeHtml('data-testid="import-same-file"'));
});

it('re-validates every row on commit (state changed after the dry-run)', function (): void {
    $run = uploadCsv($this->b, $this->admin, rosterCsv([
        ['1', 'Anna Paraudziņa', '', 'anna@example.test', '', '', '', ''],
        ['2', 'Pēteris Paraugs', syntheticPhoneTyped(2), '', '', '', '', ''],
    ]));
    inBuilding($this->b, fn () => imports()->map($this->admin, $run, $run->mapping, ImportContext::CREATE_ONLY));
    $run = inBuilding($this->b, fn () => imports()->dryRun($this->admin, $run->refresh()));
    expect($run->summary['actions']['create'])->toBe(2);

    // Meanwhile an elder adds Anna by hand.
    rosterContact($this->b, $this->admin, 1, 'Anna', email: 'anna@example.test');

    $run = inBuilding($this->b, fn () => imports()->commit($this->admin, $run, ['art14']));
    expect($run->committed_counts)->toEqual(['processed' => 2, 'created' => 1, 'updated' => 0, 'skipped' => 1])
        ->and(inBuilding($this->b, fn () => ResidentContact::query()->count()))->toBe(2);
});

it('updates the matched contact in create_or_update mode (non-empty cells only)', function (): void {
    $contact = rosterContact($this->b, $this->admin, 1, 'Anna', phones: [syntheticPhone(1)], email: 'anna@example.test');

    $run = uploadCsv($this->b, $this->admin, rosterCsv([['1', 'Anna Paraudziņa', '', 'anna@example.test', '', 'nē', 'jā', 'Vecākā']]));
    inBuilding($this->b, fn () => imports()->map($this->admin, $run, $run->mapping, ImportContext::CREATE_OR_UPDATE));
    $run = inBuilding($this->b, fn () => imports()->dryRun($this->admin, $run->refresh()));
    expect($run->summary['actions'])->toBe(['create' => 0, 'update' => 1, 'skip' => 0]);

    $run = inBuilding($this->b, fn () => imports()->commit($this->admin, $run, ['art14']));
    expect($run->committed_counts['updated'])->toBe(1);

    $plain = inBuilding($this->b, fn () => app(RosterViewer::class)->plain($contact->refresh()));
    expect($plain->name)->toBe('Anna Paraudziņa')
        ->and($plain->phones)->toBe([syntheticPhone(1)])
        ->and($plain->bisRegistered)->toBeFalse()
        ->and($plain->whatsappMember)->toBeTrue()
        ->and($plain->comment)->toBe('Vecākā');
});

it('refuses the commit without a fresh dry-run, without the Art. 14 confirmation and out of order', function (): void {
    $run = uploadCsv($this->b, $this->admin, rosterCsv([['1', 'Anna', '', '', '', '', '', '']]));

    $code = function (callable $fn): string {
        try {
            $fn();
        } catch (ImportRefused $e) {
            return $e->key;
        }

        return 'none';
    };

    expect($code(fn () => inBuilding($this->b, fn () => imports()->commit($this->admin, $run, ['art14']))))->toBe('wrong_status')
        ->and($code(fn () => inBuilding($this->b, fn () => imports()->dryRun($this->admin, $run))))->toBe('wrong_status')
        ->and($code(fn () => inBuilding($this->b, fn () => imports()->map($this->admin, $run, ['1' => 'name'], 'create_only'))))->toBe('mapping_required')
        ->and($code(fn () => inBuilding($this->b, fn () => imports()->map($this->admin, $run, ['0' => 'apartment_number', '1' => 'name', '2' => 'name'], 'create_only'))))->toBe('mapping_duplicate');

    inBuilding($this->b, fn () => imports()->map($this->admin, $run, $run->mapping, 'create_only'));
    $run = inBuilding($this->b, fn () => imports()->dryRun($this->admin, $run->refresh()));

    expect($code(fn () => inBuilding($this->b, fn () => imports()->commit($this->admin, $run, []))))->toBe('confirm_missing');

    $this->travel(31)->minutes();
    expect($code(fn () => inBuilding($this->b, fn () => imports()->commit($this->admin, $run->refresh(), ['art14']))))->toBe('dry_run_stale')
        ->and(inBuilding($this->b, fn () => ResidentContact::query()->count()))->toBe(0);
});

it('needs the mfa step-up for the commit on the page', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);
    $run = uploadCsv($this->b, $this->admin, rosterCsv([['1', 'Anna', '', '', '', '', '', '']]));
    inBuilding($this->b, fn () => imports()->map($this->admin, $run, $run->mapping, 'create_only'));
    inBuilding($this->b, fn () => imports()->dryRun($this->admin, $run->refresh()));

    app(StepUpService::class)->forget();
    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(RosterImportPage::class)
        ->set('confirmed.art14', true)
        ->call('commitImport')
        ->assertRedirectContains('/step-up'));
    expect($run->refresh()->status)->toBe(ImportRun::VALIDATED);

    app(StepUpService::class)->markFresh($this->admin, 'totp');
    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(RosterImportPage::class)
        ->set('confirmed.art14', true)
        ->call('commitImport')
        ->assertSeeHtml('data-testid="import-result"'));
    expect($run->refresh()->status)->toBe(ImportRun::COMMITTED);
});

it('runs one commit per building and kind at a time, and the job is idempotent', function (): void {
    $make = function (string $name): ImportRun {
        $run = uploadCsv($this->b, $this->admin, rosterCsv([['1', $name, '', '', '', '', '', '']]));
        inBuilding($this->b, fn () => imports()->map($this->admin, $run, $run->mapping, 'create_only'));

        return inBuilding($this->b, fn () => imports()->dryRun($this->admin, $run->refresh()));
    };
    $a = $make('Anna');
    $b = $make('Berta');

    // A is committing (its job has not run yet).
    inBuilding($this->b, fn () => ImportRun::query()->whereKey($a->id)->update(['status' => ImportRun::COMMITTING, 'options' => json_encode(['mode' => 'create_only', 'committed_by' => $this->admin->id])]));

    try {
        inBuilding($this->b, fn () => imports()->commit($this->admin, $b, ['art14']));
        $this->fail('expected commit_running');
    } catch (ImportRefused $e) {
        expect($e->key)->toBe('commit_running');
    }

    inBuilding($this->b, fn () => (new CommitImport($a->id, (int) $this->b->id))->handle(imports()));
    expect($a->refresh()->status)->toBe(ImportRun::COMMITTED)
        ->and(inBuilding($this->b, fn () => ResidentContact::query()->count()))->toBe(1);

    // A second dispatch for a committed run exits without writing.
    inBuilding($this->b, fn () => (new CommitImport($a->id, (int) $this->b->id))->handle(imports()));
    expect(inBuilding($this->b, fn () => ResidentContact::query()->count()))->toBe(1)
        ->and(inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'import.commit')->count()))->toBe(1);

    // Now B may commit.
    $b = inBuilding($this->b, fn () => imports()->commit($this->admin, $b->refresh(), ['art14']));
    expect($b->status)->toBe(ImportRun::COMMITTED);
});

it('deletes the staged payload on commit and on discard', function (): void {
    $run = uploadCsv($this->b, $this->admin, rosterCsv([['1', 'Anna', '', '', '', '', '', '']]));
    expect($run->payload_ciphertext)->not->toBeNull()
        ->and($run->payload_ciphertext)->not->toContain('Anna');

    inBuilding($this->b, fn () => imports()->discard($this->admin, $run));
    $raw = inBuilding($this->b, fn () => DB::table('import_runs')->where('id', $run->id)->first());
    expect($raw->payload_ciphertext)->toBeNull()
        ->and($raw->status)->toBe('discarded')
        ->and(fn () => inBuilding($this->b, fn () => imports()->preview($this->admin, $run->refresh())))->toThrow(ImportRefused::class);
});

it('remembers the mapping per header layout (preset)', function (): void {
    $header = ['Dz.', 'Kas', 'Tālr.', 'Pasts', 'Piezīmes'];
    $run = uploadCsv($this->b, $this->admin, rosterCsv([['1', 'Anna', '', '', '']], ';', header: $header));
    expect($run->mapping)->not->toHaveKey('1'); // "Kas" is not a synonym

    inBuilding($this->b, fn () => imports()->map($this->admin, $run, ['0' => 'apartment_number', '1' => 'name', '2' => 'phone', '3' => 'email', '4' => 'comment'], 'create_only'));
    expect(inBuilding($this->b, fn () => ImportMappingPreset::query()->count()))->toBe(1);

    $again = uploadCsv($this->b, $this->admin, rosterCsv([['2', 'Berta', '', '', '']], ';', header: $header));
    expect($again->mapping)->toBe(['0' => 'apartment_number', '1' => 'name', '2' => 'phone', '3' => 'email', '4' => 'comment']);
});

it('caps imports at 10 per building and kind per day', function (): void {
    config(['majas.import.per_day' => 2]);
    uploadCsv($this->b, $this->admin, rosterCsv([['1', 'A', '', '', '', '', '', '']]));
    uploadCsv($this->b, $this->admin, rosterCsv([['1', 'B', '', '', '', '', '', '']]));

    expect(fn () => uploadCsv($this->b, $this->admin, rosterCsv([['1', 'C', '', '', '', '', '', '']])))->toThrow(ImportRefused::class);
});

it('lets only building_admin import (elders, officers and residents are refused)', function (): void {
    $elder = rosterElder($this->b, 1);
    $officer = parkingOfficer($this->b);
    $resident = makeUser();
    member($resident, $this->b, 4);

    foreach ([$elder, $officer, $resident] as $user) {
        expect(fn () => uploadCsv($this->b, $user, rosterCsv([['1', 'Anna', '', '', '', '', '', '']])))->toThrow(ImportRefused::class);
    }

    steppedUp($elder);
    $this->actingAs($elder)->get('/admin/b/imp-house/roster/import')->assertForbidden();
});

it('previews 50 rows per page with an "only problems" filter, all in memory', function (): void {
    $rows = [];
    for ($i = 1; $i <= 24; $i++) {
        $rows[] = [(string) $i, 'Paraugs '.$i, '', '', '', '', '', ''];
    }
    for ($i = 0; $i < 40; $i++) {
        $rows[] = ['999', 'Neesošs '.$i, '', '', '', '', '', ''];
    }
    $run = uploadCsv($this->b, $this->admin, rosterCsv($rows));
    inBuilding($this->b, fn () => imports()->map($this->admin, $run, $run->mapping, 'create_only'));

    $p1 = inBuilding($this->b, fn () => imports()->preview($this->admin, $run->refresh(), 1));
    $p2 = inBuilding($this->b, fn () => imports()->preview($this->admin, $run->refresh(), 2));
    $errors = inBuilding($this->b, fn () => imports()->preview($this->admin, $run->refresh(), 1, onlyErrors: true));

    expect($p1['pages'])->toBe(2)->and($p1['rows'])->toHaveCount(50)->and($p2['rows'])->toHaveCount(14)
        ->and($errors['total'])->toBe(40)
        ->and(collect($errors['rows'])->every(fn (RowResult $r) => $r->codes === ['apartment_not_found']))->toBeTrue();

    expect(Bytea::literal('x'))->toBe('\\x78');
});

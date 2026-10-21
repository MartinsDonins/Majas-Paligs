<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\PermitSource;
use App\Enums\PermitType;
use App\Enums\VehicleSource;
use App\Filament\Manage\Pages\PermitImportPage;
use App\Models\AuditEntry;
use App\Models\Building;
use App\Models\ImportRun;
use App\Models\Permit;
use App\Models\PermitPlateObservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Import\ImportRefused;
use App\Services\Import\ImportRunService;
use App\Services\Import\RowResult;
use App\Services\Parking\PermitService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
 * ROUND2-SPEC §12 Feature/Parking/PermitImportPipelineTest (PARK-012, AC-R2-16, AC-R2-19, D6) on the shared import
 * pipeline (DB-003, kind `permits`): L0 refusal everywhere (service, forged Livewire call, no uploader), L1 import of
 * main permits (vehicles `seed_list`) and guest observations, conflicts by row number + code only, create_or_update,
 * dry-run counts = committed counts, re-validation at commit, the endorsement confirmation, masked preview, no plate
 * in Livewire snapshots, logs or audit data. Synthetic plates only.
 */
const PERMIT_CSV = "Caurlaides Nr.;Veids;Dzīvoklis;Auto numurs;Derīga no;Derīga līdz;Izsniedza\n"
    ."P-1;pamata;1;AB1234;01.10.2026;30.09.2027;Izsniedzējs\n"
    ."P-2;pamata;2;CD5678;01.10.2026;;Izsniedzējs\n"
    ."V-1;viesu;1;ZZ0001;01.10.2026;;Izsniedzējs\n"
    ."V-2;viesu;3;;01.10.2026;;Izsniedzējs\n"
    ."P-3;pamata;99;ZZ0002;01.10.2026;;Izsniedzējs\n"
    ."P-4;pamata;4;;01.10.2026;;Izsniedzējs\n"
    ."P-1;pamata;1;AB1234;01.10.2026;30.09.2027;Izsniedzējs\n"
    ."P-5;pamata;5;AB1234;01.10.2026;;Izsniedzējs\n";

beforeEach(function (): void {
    $this->b = parkingHouse('import-house', level: 1);
    $this->officer = parkingOfficer($this->b, 'import-officer@example.test');
    $this->actingAs($this->officer);
});

/**
 * The refusal key of $fn, or 'none'.
 */
function permitRefusal(callable $fn): string
{
    try {
        $fn();
    } catch (ImportRefused $e) {
        return $e->key;
    }

    return 'none';
}

/**
 * @param  list<RowResult>  $rows
 * @return array<int, list<string>> row number => codes
 */
function permitCodes(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[$r->number] = $r->codes;
    }

    return $out;
}

/**
 * Upload → map (suggested) → dry-run → commit, as $officer; returns [committed run, dry-run run].
 *
 * @return array{0: ImportRun, 1: ImportRun}
 */
function permitImport(Building $b, User $officer, string $csv, string $mode = 'create_only'): array
{
    return inBuilding($b, function () use ($officer, $csv, $mode): array {
        $imports = app(ImportRunService::class);
        $run = $imports->upload($officer, 'permits', $csv);
        $imports->map($officer, $run, $run->mapping, $mode);
        $dry = $imports->dryRun($officer, $run->refresh());
        $dryCopy = clone $dry;
        $imports->commit($officer, $dry, ['endorsed']);

        return [$run->refresh(), $dryCopy];
    });
}

it('L0: refuses the upload in the service and a forged Livewire call; the page shows the card without an uploader', function (): void {
    $l0 = parkingHouse('import-l0', level: 0);
    $officer = parkingOfficer($l0, 'l0-officer@example.test');

    inBuilding($l0, function () use ($officer): void {
        expect(permitRefusal(fn () => app(ImportRunService::class)->upload($officer, 'permits', PERMIT_CSV)))->toBe('gate_closed')
            ->and(ImportRun::query()->count())->toBe(0);
    });

    steppedUp($officer);
    $this->actingAs($officer)->get('/admin/b/import-l0/permit-import')->assertOk()
        ->assertSee(__('import.gate.title'))
        ->assertSee(__('import.gate.lead.permits'))
        ->assertSee(__('gate.parking.missing.dpa_scope_parking'))
        ->assertSee(__('import.gate.open_wizard'))
        ->assertDontSee('type="file"', false);

    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($l0);
    inBuilding($l0, function () use ($officer): void {
        Livewire::actingAs($officer)->test(PermitImportPage::class)->set('paste', PERMIT_CSV)->call('uploadPaste');
        Livewire::actingAs($officer)->test(PermitImportPage::class)->set('upload', UploadedFile::fake()->createWithContent('saraksts.csv', PERMIT_CSV))
            ->call('uploadFile');
        expect(ImportRun::query()->count())->toBe(0);
    });
});

it('L1: imports main permits with seed_list vehicles and guest observations, errors by row number + code', function (): void {
    activeVehicle($this->b, 6, 'CD9999', $this->officer); // unrelated active car

    $preview = inBuilding($this->b, function (): array {
        $imports = app(ImportRunService::class);
        $run = $imports->upload($this->officer, 'permits', PERMIT_CSV);
        $imports->map($this->officer, $run, $run->mapping, 'create_only');

        return $imports->preview($this->officer, $run->refresh());
    });
    $codes = permitCodes($preview['rows']);
    expect($codes[2])->toBe([])
        ->and($codes[6])->toBe(['apartment_not_found'])
        ->and($codes[7])->toBe(['main_without_plate'])
        ->and($codes[8])->toBe(['duplicate_in_file'])
        ->and($codes[9])->toBe(['plate_conflict'])
        ->and($preview['rows'][0]->preview['plate'])->toBe('AB••34');
    inBuilding($this->b, fn () => ImportRun::query()->update(['status' => ImportRun::DISCARDED, 'payload_ciphertext' => null]));

    [$run, $dry] = permitImport($this->b, $this->officer, PERMIT_CSV);

    expect($run->status)->toBe(ImportRun::COMMITTED)
        ->and($run->payload_ciphertext)->toBeNull()
        ->and($dry->summary['actions'])->toBe(['create' => 4, 'update' => 0, 'skip' => 4])
        ->and($run->committed_counts)->toEqual(['processed' => 8, 'created' => 4, 'updated' => 0, 'skipped' => 4])
        ->and(json_encode([$run->summary, $run->committed_counts]))->not->toContain('AB1234')->not->toContain('ZZ0001');

    inBuilding($this->b, function (): void {
        $permits = Permit::query()->orderBy('number')->get()->keyBy('number');
        expect($permits->keys()->all())->toBe(['P-1', 'P-2', 'V-1', 'V-2'])
            ->and($permits['P-1']->source)->toBe(PermitSource::Import)
            ->and($permits['P-1']->import_run_id)->not->toBeNull()
            ->and($permits['P-1']->valid_to->format('Y-m-d'))->toBe('2027-09-30')
            ->and($permits['P-1']->issued_by)->toBe('Izsniedzējs')
            ->and($permits['V-1']->type)->toBe(PermitType::Guest)
            ->and($permits['V-1']->vehicle_id)->toBeNull();

        $vehicle = Vehicle::query()->findOrFail($permits['P-1']->vehicle_id);
        expect($vehicle->source)->toBe(VehicleSource::SeedList)->and($vehicle->status->value)->toBe('active')
            ->and((int) $vehicle->apartment_id)->toBe((int) apartment($this->b, 1)->id);

        $obs = PermitPlateObservation::query()->get();
        expect($obs)->toHaveCount(1)
            ->and((int) $obs[0]->permit_id)->toBe((int) $permits['V-1']->id)
            ->and($obs[0]->source->value)->toBe('import')
            ->and($obs[0]->observed_on->toDateString())->toBe(CarbonImmutable::now('Europe/Riga')->toDateString());
    });

    // Audit: counts only — never values, never a file name.
    $commit = inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'import.commit')->firstOrFail());
    expect($commit->data)->toEqual(['kind' => 'permits', 'rows' => 8, 'created' => 4, 'updated' => 0, 'skipped' => 4])
        ->and((int) $commit->actor_user_id)->toBe((int) $this->officer->id);
});

it('maps Google Sheets (",", UTF-8), Excel LV (";", Windows-1257) and RU headers automatically', function (): void {
    $google = "Nr,Tips,Dz.,Auto,Līdz\nG-1,viesu,7,,31.12.2026\n";
    $win1257 = iconv('UTF-8', 'WINDOWS-1257', "Caurlaides numurs;Pamata/viesu;Dzīvokļa Nr.;Valsts numurs;Izsniedza\nW-1;pamata;8;AB1234;Pārvaldnieks Šķūnis\n");
    $bom = "\xEF\xBB\xBF"."Номер пропуска;Тип;Квартира;Госномер\nR-1;основной;9;CD5678\n";

    inBuilding($this->b, function () use ($google, $win1257, $bom): void {
        $imports = app(ImportRunService::class);

        $g = $imports->upload($this->officer, 'permits', $google);
        expect($g->delimiter)->toBe(',')->and($g->has_header)->toBeTrue()
            ->and(array_values($g->mapping))->toBe(['permit_number', 'type', 'apartment_number', 'plate', 'valid_to']);

        $w = $imports->upload($this->officer, 'permits', $win1257);
        expect($w->encoding)->toBe('windows-1257')->and($w->delimiter)->toBe(';')
            ->and(array_values($w->mapping))->toBe(['permit_number', 'type', 'apartment_number', 'plate', 'issued_by']);
        $imports->map($this->officer, $w, $w->mapping, 'create_only');
        $preview = $imports->preview($this->officer, $w->refresh());
        expect($preview['rows'][0]->preview['issued_by'])->toBe('Pārvaldnieks Šķūnis')
            ->and($preview['rows'][0]->preview['plate'])->toBe('AB••34')
            ->and($preview['rows'][0]->codes)->toBe([]);

        $r = $imports->upload($this->officer, 'permits', $bom);
        expect(array_values($r->mapping))->toBe(['permit_number', 'type', 'apartment_number', 'plate']);
    });
});

it('updates validity, status and issuer in create_or_update mode and never moves a permit', function (): void {
    $car = activeVehicle($this->b, 1, 'AB1234', $this->officer);
    inBuilding($this->b, function () use ($car): void {
        app(PermitService::class)->issue($this->officer, apartment($this->b, 1), 'P-1', PermitType::Main, $car, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));
        app(PermitService::class)->issue($this->officer, apartment($this->b, 2), 'V-7', PermitType::Guest, null, CarbonImmutable::parse('2026-01-01'));
        app(PermitService::class)->issue($this->officer, apartment($this->b, 2), 'V-8', PermitType::Guest, null, CarbonImmutable::parse('2026-01-01'));
    });

    $csv = "Nr;Veids;Dzīvoklis;Auto;Līdz;Statuss;Izsniedza\n"
        ."P-1;pamata;1;;31.12.2027;aktīva;Jaunais izsniedzējs\n"
        ."V-8;viesu;3;;;;\n"
        ."V-7;viesu;2;ZZ0003;;nodota;\n";

    [$run, $dry] = permitImport($this->b, $this->officer, $csv, 'create_or_update');

    expect($dry->summary['actions'])->toBe(['create' => 0, 'update' => 2, 'skip' => 1])
        ->and($dry->summary['codes'])->toMatchArray(['apartment_change_refused' => 1, 'exists_update' => 2])
        ->and($run->committed_counts['updated'])->toBe(2);

    inBuilding($this->b, function (): void {
        $p1 = Permit::query()->where('number', 'P-1')->firstOrFail();
        $v7 = Permit::query()->where('number', 'V-7')->firstOrFail();
        expect($p1->valid_to->toDateString())->toBe('2027-12-31')->and($p1->issued_by)->toBe('Jaunais izsniedzējs')
            ->and($p1->source)->toBe(PermitSource::Officer)
            ->and($v7->status->value)->toBe('returned')->and((int) $v7->apartment_id)->toBe((int) apartment($this->b, 2)->id)
            ->and(Permit::query()->count())->toBe(3)
            ->and((int) Permit::query()->where('number', 'V-8')->value('apartment_id'))->toBe((int) apartment($this->b, 2)->id)
            ->and(PermitPlateObservation::query()->where('permit_id', $v7->id)->count())->toBe(1);
    });
});

it('skips existing permits in create_only mode', function (): void {
    [$first] = permitImport($this->b, $this->officer, PERMIT_CSV);
    expect($first->committed_counts['created'])->toBe(4);

    $this->travel(2)->days();
    $again = inBuilding($this->b, function (): ImportRun {
        $imports = app(ImportRunService::class);
        $run = $imports->upload($this->officer, 'permits', PERMIT_CSV);
        $imports->map($this->officer, $run, $run->mapping, 'create_only');

        return $imports->dryRun($this->officer, $run->refresh());
    });
    expect($again->summary['actions']['create'])->toBe(0)->and($again->summary['codes']['exists_skip'])->toBe(4);
});

it('re-validates every row at commit: state changed after the dry-run is honoured', function (): void {
    inBuilding($this->b, function (): void {
        $imports = app(ImportRunService::class);
        $run = $imports->upload($this->officer, 'permits', "Nr;Veids;Dzīvoklis;Auto\nP-1;pamata;1;AB1234\nP-2;pamata;2;CD5678\n");
        $imports->map($this->officer, $run, $run->mapping, 'create_only');
        $run = $imports->dryRun($this->officer, $run->refresh());
        expect($run->summary['actions']['create'])->toBe(2);

        // Meanwhile CD5678 becomes active in apartment 4.
        activeVehicle($this->b, 4, 'CD5678', $this->officer);

        $run = $imports->commit($this->officer, $run, ['endorsed']);
        expect($run->committed_counts['created'])->toBe(1)->and($run->committed_counts['skipped'])->toBe(1)
            ->and(Permit::query()->count())->toBe(1);
    });
});

it('needs a fresh dry-run and the endorsement before the commit', function (): void {
    inBuilding($this->b, function (): void {
        $imports = app(ImportRunService::class);
        $run = $imports->upload($this->officer, 'permits', "Nr;Veids;Dzīvoklis\nV-1;viesu;1\nV-2;viesu;2\n");
        $imports->map($this->officer, $run, $run->mapping, 'create_only');

        expect(permitRefusal(fn () => $imports->commit($this->officer, $run->refresh(), ['endorsed'])))->toBe('wrong_status');
        $run = $imports->dryRun($this->officer, $run->refresh());
        expect(permitRefusal(fn () => $imports->commit($this->officer, $run, [])))->toBe('confirm_missing');

        $this->travel(31)->minutes();
        expect(permitRefusal(fn () => $imports->commit($this->officer, $run->refresh(), ['endorsed'])))->toBe('dry_run_stale');
        $run = $imports->dryRun($this->officer, $run->refresh());

        $run = $imports->commit($this->officer, $run, ['endorsed']);
        expect($run->status)->toBe(ImportRun::COMMITTED)->and(Permit::query()->count())->toBe(2);
    });
});

it('seals the payload with the plate key and binds it to its run', function (): void {
    inBuilding($this->b, function (): void {
        $imports = app(ImportRunService::class);
        $run = $imports->upload($this->officer, 'permits', PERMIT_CSV);
        expect($run->payload_ciphertext)->not->toBeNull()
            ->and(str_contains((string) $run->payload_ciphertext, 'AB1234'))->toBeFalse();

        $imports->discard($this->officer, $run);
        expect($run->refresh()->status)->toBe(ImportRun::DISCARDED)->and($run->payload_ciphertext)->toBeNull();
    });
});

it('drives the page end to end with masked preview and no plate in the Livewire snapshot', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);
    app(StepUpService::class)->markFresh($this->officer, 'totp');

    inBuilding($this->b, function (): void {
        $page = Livewire::actingAs($this->officer)->test(PermitImportPage::class)
            ->assertSee(__('import.upload.title'))
            ->set('upload', UploadedFile::fake()->createWithContent('Jānis Bērziņš saraksts.csv', PERMIT_CSV))
            ->call('uploadFile')
            ->assertSee(__('import.mapping.title'))
            ->assertSee('Auto numurs')
            ->call('saveMapping')
            ->assertSee(__('import.preview.title'))
            ->assertSee('AB••34')
            ->assertDontSee('AB1234')
            ->assertSee(__('import.code.apartment_not_found'));

        expect($page->html())->not->toContain('AB1234')->not->toContain('CD5678')->not->toContain('ZZ0001')
            ->and(json_encode($page->snapshot))->not->toContain('AB1234');

        $page->call('dryRun')
            ->assertSee(__('import.dry_run.counts', ['create' => 4, 'update' => 0, 'skip' => 4]))
            ->call('commitImport');

        expect(Permit::query()->count())->toBe(0);

        $page->set('confirmed.endorsed', true)->call('commitImport')
            ->assertSeeHtml('data-testid="import-result"')
            ->assertDontSee('AB1234');

        expect(Permit::query()->count())->toBe(4)
            ->and($page->html())->not->toContain('AB1234');
    });

    // The file name never reaches the audit log.
    expect(inBuilding($this->b, fn () => AuditEntry::query()->pluck('data')->toJson()))->not->toContain('Jānis')->not->toContain('saraksts');
});

it('asks for a fresh MFA step-up before the commit', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);

    inBuilding($this->b, function (): void {
        Livewire::actingAs($this->officer)->test(PermitImportPage::class)
            ->set('paste', "Nr;Veids;Dzīvoklis\nV-1;viesu;1\n")
            ->call('uploadPaste')
            ->assertSet('paste', '')
            ->call('saveMapping')
            ->call('dryRun')
            ->set('confirmed.endorsed', true)
            ->call('commitImport')
            ->assertRedirectContains('/step-up');

        expect(Permit::query()->count())->toBe(0);
    });
});

it('never writes a cell value to the log while importing', function (): void {
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = json_encode([$message->message, $message->context]);
    });

    permitImport($this->b, $this->officer, PERMIT_CSV."P-6;nezinams;1;AB1234\n");

    expect(implode("\n", $logged))->not->toContain('AB1234')->not->toContain('CD5678')->not->toContain('Izsniedzējs');
});

it('gives the import to parking staff only', function (): void {
    $admin = makeUser('import-admin@example.test');
    grantRole($admin, $this->b, BuildingRoleType::BuildingAdmin);
    $resident = parkingResident($this->b, 5);

    steppedUp($admin);
    $this->actingAs($admin)->get('/admin/b/import-house/permit-import')->assertOk()->assertSee(__('import.upload.title'));

    inBuilding($this->b, function () use ($resident): void {
        expect(permitRefusal(fn () => app(ImportRunService::class)->upload($resident, 'permits', PERMIT_CSV)))->toBe('forbidden');
    });
});

it('generates the permit template at runtime without a plate-shaped example', function (): void {
    $admin = makeUser('template-admin@example.test');
    grantRole($admin, $this->b, BuildingRoleType::BuildingAdmin);
    steppedUp($admin);

    $csv = $this->actingAs($admin)->get('/admin/b/import-house/files/import-template/permits.csv')->assertOk()->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")->toContain(__('import.field.permit_number'))
        ->and(preg_match('/[A-Z]{2}\d{3,4}/', $csv))->toBe(0);
});

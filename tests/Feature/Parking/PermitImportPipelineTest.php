<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Casts\Bytea;
use App\Enums\BuildingRoleType;
use App\Enums\ImportKind;
use App\Enums\ImportStatus;
use App\Enums\PermitSource;
use App\Enums\PermitType;
use App\Enums\VehicleSource;
use App\Filament\Manage\Pages\PermitImportPage;
use App\Jobs\CommitImport;
use App\Models\AuditEntry;
use App\Models\Building;
use App\Models\ImportRun;
use App\Models\Permit;
use App\Models\PermitPlateObservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Import\CsvReader;
use App\Services\Import\ImportFailed;
use App\Services\Import\ImportRunService;
use App\Services\Parking\PermitService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
 * ROUND2-SPEC §12 Feature/Parking/PermitImportPipelineTest (PARK-012, AC-R2-16, AC-R2-19, D6) on the shared import
 * pipeline (DB-003): L0 refusal everywhere (service, model guard, forged Livewire call, no uploader), L1 import of main
 * permits (vehicles `seed_list`) and guest observations, conflicts by row number + code only, create_or_update, dry-run
 * counts = committed counts, re-validation at commit, idempotent job, one commit at a time, same-file detection,
 * payload deletion, masked preview, no plate in Livewire snapshots, logs or audit data. Synthetic plates only.
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
 * Upload → map (suggested) → dry-run → commit, as the officer; returns [run, dry-run counts].
 *
 * @return array{0: ImportRun, 1: array<string, mixed>}
 */
function permitImport(Building $b, User $officer, string $csv, string $mode = 'create_only'): array
{
    return inBuilding($b, function () use ($officer, $csv, $mode): array {
        $imports = app(ImportRunService::class);
        $run = $imports->upload($officer, ImportKind::Permits, $csv);
        $imports->map($officer, $run, $run->mapping, $mode);
        $counts = $imports->dryRun($run);
        $imports->commit($officer, $run->refresh(), true);

        return [$run->refresh(), $counts];
    });
}

it('L0: refuses the upload in the service, the model guard and a forged Livewire call; the page shows the card', function (): void {
    $l0 = parkingHouse('import-l0', level: 0);
    $officer = parkingOfficer($l0, 'l0-officer@example.test');

    inBuilding($l0, function () use ($officer): void {
        expect(fn () => app(ImportRunService::class)->upload($officer, ImportKind::Permits, PERMIT_CSV))->toThrow(ImportFailed::class, 'gate_closed')
            ->and(fn () => ImportRun::query()->create([
                'kind' => ImportKind::Permits, 'file_sha256' => random_bytes(32), 'expires_at' => now()->addDay(),
            ]))->toThrow(ImportFailed::class, 'gate_closed')
            ->and(ImportRun::query()->count())->toBe(0);
    });

    steppedUp($officer);
    $this->actingAs($officer)->get('/admin/b/import-l0/permit-import')->assertOk()
        ->assertSee(__('admin.parking.import.closed_title'))
        ->assertSee(__('admin.parking.import.closed_body'))
        ->assertSee(__('gate.parking.missing.dpa_scope_parking'))
        ->assertSee(__('admin.parking.import.open_wizard'))
        ->assertDontSee('type="file"', false);

    // A forged call of the upload action below L1 is refused server-side (D6) and stores nothing.
    $this->actingAs($officer);
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($l0);
    inBuilding($l0, function () use ($officer): void {
        Livewire::actingAs($officer)->test(PermitImportPage::class)->call('uploadPaste', PERMIT_CSV)->assertForbidden();
        Livewire::actingAs($officer)->test(PermitImportPage::class)->set('upload', UploadedFile::fake()->createWithContent('saraksts.csv', PERMIT_CSV))
            ->call('uploadFile')->assertForbidden();
        expect(ImportRun::query()->count())->toBe(0);
    });
});

it('L1: imports main permits with seed_list vehicles and guest observations, errors by row number + code', function (): void {
    activeVehicle($this->b, 6, 'CD9999', $this->officer); // unrelated active car

    [$run, $dry] = permitImport($this->b, $this->officer, PERMIT_CSV);

    expect($run->status)->toBe(ImportStatus::Committed)
        ->and($run->payload_ciphertext)->toBeNull()
        ->and($dry['create'])->toBe(4)->and($dry['skip'])->toBe(4)
        ->and($run->committed_counts['created'])->toBe($dry['create'])
        ->and($run->committed_counts['skipped'])->toBe($dry['skip'])
        ->and($run->committed_counts['updated'])->toBe(0);

    $report = collect($run->committed_counts['report'])->keyBy('row');
    expect($report[6]['codes'])->toBe(['apartment_not_found'])
        ->and($report[7]['codes'])->toBe(['main_without_plate'])
        ->and($report[8]['codes'])->toBe(['duplicate_in_file'])
        ->and($report[9]['codes'])->toBe(['plate_conflict']);
    expect(json_encode($run->committed_counts))->not->toContain('AB1234')->not->toContain('ZZ0001');

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
    $commit = AuditEntry::query()->where('action', 'import.commit')->firstOrFail();
    expect($commit->data)->toEqual(['kind' => 'permits', 'rows' => 8, 'created' => 4, 'updated' => 0, 'skipped' => 4])
        ->and((int) $commit->actor_user_id)->toBe((int) $this->officer->id)
        ->and(AuditEntry::query()->where('action', 'import.upload')->value('data'))->toBe(['kind' => 'permits', 'rows' => 8]);
});

it('maps Google Sheets (",", UTF-8) and Excel LV (";", BOM, Windows-1257) headers automatically', function (): void {
    $google = "Nr,Tips,Dz.,Auto,Līdz\nG-1,viesu,7,,31.12.2026\n";
    $win1257 = iconv('UTF-8', 'WINDOWS-1257', "Caurlaides numurs;Pamata/viesu;Dzīvokļa Nr.;Valsts numurs;Izsniedza\nW-1;pamata;8;AB1234;Pārvaldnieks Šķūnis\n");
    $bom = "\xEF\xBB\xBF"."Номер пропуска;Тип;Квартира;Госномер\nR-1;основной;9;CD5678\n";

    inBuilding($this->b, function () use ($google, $win1257, $bom): void {
        $imports = app(ImportRunService::class);

        $g = $imports->upload($this->officer, ImportKind::Permits, $google);
        expect($g->delimiter)->toBe(',')->and($g->encoding)->toBe('utf-8')->and($g->has_header)->toBeTrue()
            ->and($g->mapping)->toBe(['0' => 'permit_number', '1' => 'type', '2' => 'apartment_number', '3' => 'plate', '4' => 'valid_to']);

        $w = $imports->upload($this->officer, ImportKind::Permits, $win1257);
        expect($w->encoding)->toBe('windows-1257')->and($w->delimiter)->toBe(';')
            ->and($w->mapping)->toBe(['0' => 'permit_number', '1' => 'type', '2' => 'apartment_number', '3' => 'plate', '4' => 'issued_by']);
        $imports->map($this->officer, $w, $w->mapping);
        $preview = $imports->preview($w->refresh());
        expect($preview['rows'][0]['cells']['issued_by'])->toBe('Pārvaldnieks Šķūnis')
            ->and($preview['rows'][0]['cells']['plate'])->toBe('AB••34')
            ->and($preview['rows'][0]['status'])->toBe('ok');

        $r = $imports->upload($this->officer, ImportKind::Permits, $bom);
        expect($r->encoding)->toBe('utf-8')
            ->and($r->mapping)->toBe(['0' => 'permit_number', '1' => 'type', '2' => 'apartment_number', '3' => 'plate']);
    });
});

it('remembers a mapping for the same header layout', function (): void {
    $csv = "Caurlaide;Kas;Dzīvoklis;Mašīna\nP-9;pamata;3;AB1234\n";

    inBuilding($this->b, function () use ($csv): void {
        $imports = app(ImportRunService::class);
        $first = $imports->upload($this->officer, ImportKind::Permits, $csv);
        expect($first->has_header)->toBeTrue()->and($first->mapping)->toBe(['0' => 'permit_number', '2' => 'apartment_number']);
        $imports->map($this->officer, $first, [0 => 'permit_number', 1 => 'type', 2 => 'apartment_number', 3 => 'plate']);

        $second = $imports->upload($this->officer, ImportKind::Permits, $csv."P-10;viesu;4;\n");
        expect($second->mapping)->toBe(['0' => 'permit_number', '1' => 'type', '2' => 'apartment_number', '3' => 'plate']);
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

    expect($dry['update'])->toBe(2)->and($dry['skip'])->toBe(1)
        ->and($run->committed_counts['updated'])->toBe(2)
        ->and(collect($run->committed_counts['report'])->firstWhere('row', 3)['codes'])->toBe(['apartment_change_refused']);

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

it('skips existing permits in create_only mode and detects a second upload of the same file', function (): void {
    [$first] = permitImport($this->b, $this->officer, PERMIT_CSV);
    expect($first->committed_counts['created'])->toBe(4);

    inBuilding($this->b, function () use ($first): void {
        $imports = app(ImportRunService::class);
        $again = $imports->upload($this->officer, ImportKind::Permits, PERMIT_CSV);
        expect($imports->sameFileCommitted($again)?->id)->toBe($first->id);

        $imports->map($this->officer, $again, $again->mapping);
        $counts = $imports->dryRun($again);
        expect($counts['create'])->toBe(0)->and($counts['codes']['exists_skip'])->toBe(4);
    });
});

it('re-validates every row at commit: state changed after the dry-run is honoured', function (): void {
    inBuilding($this->b, function (): void {
        $imports = app(ImportRunService::class);
        $run = $imports->upload($this->officer, ImportKind::Permits, "Nr;Veids;Dzīvoklis;Auto\nP-1;pamata;1;AB1234\nP-2;pamata;2;CD5678\n");
        $imports->map($this->officer, $run, $run->mapping);
        expect($imports->dryRun($run)['create'])->toBe(2);

        // Meanwhile CD5678 becomes active in apartment 4.
        activeVehicle($this->b, 4, 'CD5678', $this->officer);

        $imports->commit($this->officer, $run->refresh(), true);
        $run->refresh();
        expect($run->committed_counts['created'])->toBe(1)->and($run->committed_counts['skipped'])->toBe(1)
            ->and(collect($run->committed_counts['report'])->firstWhere('row', 3)['codes'])->toBe(['plate_conflict']);
    });
});

it('needs a fresh dry-run and the endorsement, allows one commit at a time and is idempotent', function (): void {
    inBuilding($this->b, function (): void {
        $imports = app(ImportRunService::class);
        $run = $imports->upload($this->officer, ImportKind::Permits, "Nr;Veids;Dzīvoklis\nV-1;viesu;1\nV-2;viesu;2\n");
        $imports->map($this->officer, $run, $run->mapping);

        expect(fn () => $imports->commit($this->officer, $run->refresh(), true))->toThrow(ImportFailed::class, 'wrong_stage');
        $imports->dryRun($run);
        expect(fn () => $imports->commit($this->officer, $run->refresh(), false))->toThrow(ImportFailed::class, 'confirm_required');

        $this->travel(31)->minutes();
        expect(fn () => $imports->commit($this->officer, $run->refresh(), true))->toThrow(ImportFailed::class, 'dry_run_stale');
        $imports->dryRun($run->refresh());

        // Another run of the same kind is committing → this one waits (partial UNIQUE).
        $other = $imports->upload($this->officer, ImportKind::Permits, "Nr;Veids;Dzīvoklis\nV-9;viesu;9\n");
        DB::table('import_runs')->where('id', $other->id)->update(['status' => 'committing']);
        expect(fn () => $imports->commit($this->officer, $run->refresh(), true))->toThrow(ImportFailed::class, 'commit_running');
        DB::table('import_runs')->where('id', $other->id)->update(['status' => 'discarded']);

        $imports->commit($this->officer, $run->refresh(), true);
        expect($run->refresh()->status)->toBe(ImportStatus::Committed)->and(Permit::query()->count())->toBe(2);

        // A second dispatch of the same run writes nothing.
        (new CommitImport((int) $this->b->id, (string) $run->id))->handle($imports);
        $imports->runCommit((string) $run->id);
        expect(Permit::query()->count())->toBe(2)->and($run->refresh()->committed_counts['created'])->toBe(2);
    });
});

it('deletes the payload on discard and refuses expired runs', function (): void {
    inBuilding($this->b, function (): void {
        $imports = app(ImportRunService::class);
        $run = $imports->upload($this->officer, ImportKind::Permits, PERMIT_CSV);
        expect($run->payload_ciphertext)->not->toBeNull()
            ->and(str_contains((string) $run->payload_ciphertext, 'AB1234'))->toBeFalse();

        $imports->discard($this->officer, $run);
        expect($run->refresh()->status)->toBe(ImportStatus::Discarded)->and($run->payload_ciphertext)->toBeNull();

        $late = $imports->upload($this->officer, ImportKind::Permits, PERMIT_CSV);
        $this->travel(25)->hours();
        expect(fn () => $imports->map($this->officer, $late->refresh(), $late->mapping))->toThrow(ImportFailed::class, 'expired');
        expect($late->refresh()->status)->toBe(ImportStatus::Expired)->and($late->payload_ciphertext)->toBeNull();
    });
});

it('binds the sealed payload to its run, building and kind', function (): void {
    inBuilding($this->b, function (): void {
        $imports = app(ImportRunService::class);
        $a = $imports->upload($this->officer, ImportKind::Permits, PERMIT_CSV);
        $b = $imports->upload($this->officer, ImportKind::Permits, "Nr;Veids;Dzīvoklis\nV-1;viesu;1\n");

        DB::table('import_runs')->where('id', $b->id)->update(['payload_ciphertext' => Bytea::literal((string) $a->payload_ciphertext)]);
        expect(fn () => $imports->table($b->refresh()))->toThrow(ImportFailed::class, 'payload_gone');
    });
});

it('refuses file types, oversize files and too many rows by code', function (): void {
    inBuilding($this->b, function (): void {
        $imports = app(ImportRunService::class);
        $rows = "Nr;Veids;Dzīvoklis\n".str_repeat("V-1;viesu;1\n", 2001);

        expect(fn () => $imports->upload($this->officer, ImportKind::Permits, $rows))->toThrow(ImportFailed::class, 'too_many_rows')
            ->and(fn () => $imports->upload($this->officer, ImportKind::Permits, str_repeat('x', 2 * 1024 * 1024 + 1)))->toThrow(ImportFailed::class, 'too_large')
            ->and(fn () => $imports->upload($this->officer, ImportKind::Permits, "\n\n"))->toThrow(ImportFailed::class, 'empty');
    });

    $png = UploadedFile::fake()->image('saraksts.png');
    expect(fn () => app(CsvReader::class)->assertUpload($png->getRealPath(), 'saraksts.csv'))->toThrow(ImportFailed::class, 'file_type');
});

it('caps uploads at 10 a day per building and kind', function (): void {
    inBuilding($this->b, function (): void {
        $imports = app(ImportRunService::class);
        foreach (range(1, 10) as $i) {
            $imports->upload($this->officer, ImportKind::Permits, "Nr;Veids;Dzīvoklis\nV-{$i};viesu;1\n");
        }
        expect(fn () => $imports->upload($this->officer, ImportKind::Permits, "Nr;Veids;Dzīvoklis\nV-11;viesu;1\n"))->toThrow(ImportFailed::class, 'import_quota');
    });
});

it('drives the page end to end with masked preview and no plate in the Livewire snapshot', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);
    app(StepUpService::class)->markFresh($this->officer, 'totp');

    inBuilding($this->b, function (): void {
        $page = Livewire::actingAs($this->officer)->test(PermitImportPage::class)
            ->assertSee(__('admin.import.step.upload'))
            ->set('upload', UploadedFile::fake()->createWithContent('Jānis Bērziņš saraksts.csv', PERMIT_CSV))
            ->call('uploadFile')
            ->assertSee(__('admin.import.mapping_title'))
            ->assertSee('Auto numurs')
            ->call('saveMapping')
            ->assertSee(__('admin.import.preview_title'))
            ->assertSee('AB••34')
            ->assertDontSee('AB1234')
            ->assertSee(__('admin.import.code.apartment_not_found'));

        // html() includes the root element's wire:snapshot — no plate in the state or in the rendered page.
        expect($page->html())->not->toContain('AB1234')->not->toContain('CD5678')->not->toContain('ZZ0001');

        $page->call('dryRun')
            ->assertSee(__('admin.import.dry_run_counts', ['create' => 4, 'update' => 0, 'skip' => 4]))
            ->call('commit')
            ->assertNotified(__('admin.parking.import.endorse_first'));

        expect(Permit::query()->count())->toBe(0);

        $page->set('endorsed', true)->call('commit')
            ->assertSee(__('admin.import.committed', ['created' => 4, 'updated' => 0, 'skipped' => 4]))
            ->assertSee(__('admin.import.code.plate_conflict'))
            ->assertDontSee('AB1234');

        expect(Permit::query()->count())->toBe(4)
            ->and($page->html())->not->toContain('AB1234');
    });

    // The file name never reaches the audit log.
    expect(AuditEntry::query()->pluck('data')->toJson())->not->toContain('Jānis')->not->toContain('saraksts');
});

it('pastes CSV as an action argument (never a public property)', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);

    inBuilding($this->b, function (): void {
        $page = Livewire::actingAs($this->officer)->test(PermitImportPage::class)->call('uploadPaste', PERMIT_CSV)
            ->assertSee(__('admin.import.mapping_title'));

        expect($page->get('runId'))->not->toBeNull()
            ->and($page->html())->not->toContain('AB1234');
    });
});

it('asks for a fresh MFA step-up before the commit', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);

    inBuilding($this->b, function (): void {
        Livewire::actingAs($this->officer)->test(PermitImportPage::class)
            ->call('uploadPaste', "Nr;Veids;Dzīvoklis\nV-1;viesu;1\n")
            ->call('saveMapping')
            ->call('dryRun')
            ->set('endorsed', true)
            ->call('commit')
            ->assertRedirect(route('step-up', absolute: false));

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

it('gives the import page only to parking staff', function (): void {
    $admin = makeUser('import-admin@example.test');
    grantRole($admin, $this->b, BuildingRoleType::BuildingAdmin);
    $resident = parkingResident($this->b, 5);

    steppedUp($admin);
    $this->actingAs($admin)->get('/admin/b/import-house/permit-import')->assertOk()->assertSee(__('admin.import.step.upload'));

    inBuilding($this->b, function () use ($resident): void {
        expect(fn () => app(ImportRunService::class)->upload($resident, ImportKind::Permits, PERMIT_CSV))->toThrow(ImportFailed::class, 'forbidden');
    });
});

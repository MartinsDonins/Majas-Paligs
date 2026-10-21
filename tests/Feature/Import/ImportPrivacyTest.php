<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Filament\Manage\Pages\RosterImportPage;
use App\Logging\RedactSecrets;
use App\Models\AuditEntry;
use App\Models\ImportRun;
use App\Services\Import\CsvReader;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportRefused;
use App\Services\Import\ImportRunService;
use Filament\Facades\Filament;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * AC-R2-19 (Feature/Import/ImportPrivacyTest): no cell value ever appears in a Livewire snapshot, a log line, an audit
 * row's data or an exception message; the staged payload is sealed and the temporary upload deleted in the same
 * request.
 */
beforeEach(function (): void {
    $this->b = rosterHouse('priv-house');
    $this->admin = rosterAdmin($this->b, 'priv-admin@example.test');
    $this->actingAs($this->admin);
    app(StepUpService::class)->markFresh($this->admin, 'totp');
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);

    $this->secretPhone = syntheticPhoneTyped(91);
    $this->csv = rosterCsv([
        ['1', 'Slepens Vārds', $this->secretPhone, 'slepens@example.test', '', '', '', 'Slepena piezīme'],
        ['999', 'Nav Dzīvokļa', '', '', '', '', '', ''],
    ]);
});

it('keeps cell values out of the Livewire snapshot through every stage (paste, mapping, preview, dry-run, commit)', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
        $logged[] = $e->message.' '.json_encode($e->context);
    });

    $page = inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(RosterImportPage::class)
        ->set('paste', $this->csv)
        ->call('uploadPaste')
        ->assertSet('paste', ''));

    $snapshots = [json_encode($page->snapshot)];
    $html = $page->html();

    $page = inBuilding($this->b, fn () => $page->call('saveMapping'));
    $snapshots[] = json_encode($page->snapshot);
    $preview = $page->html();
    expect($preview)->toContain('Slepens Vārds') // names are shown in the preview…
        ->not->toContain('slepens@example.test') // …phones and e-mails masked
        ->not->toContain(str_replace(' ', '', $this->secretPhone));

    $page = inBuilding($this->b, fn () => $page->call('dryRun')->set('confirmed.art14', true)->call('commitImport'));
    $snapshots[] = json_encode($page->snapshot);

    foreach ($snapshots as $snapshot) {
        expect($snapshot)->not->toContain('Slepens')
            ->not->toContain('slepens@example.test')
            ->not->toContain(str_replace(' ', '', $this->secretPhone))
            ->not->toContain('Slepena piezīme');
    }
    expect($html)->not->toContain('slepens@example.test');

    // Audit rows: counts and ids only.
    $audit = json_encode(inBuilding($this->b, fn () => AuditEntry::query()->get(['action', 'data'])->toArray()));
    expect($audit)->toContain('import.upload')->toContain('import.commit')
        ->not->toContain('Slepens')->not->toContain('slepens@')->not->toContain('piezīme');

    // Logs: nothing at all about the cells.
    expect(implode("\n", $logged))->not->toContain('Slepens')->not->toContain('slepens@');

    $run = inBuilding($this->b, fn () => ImportRun::query()->latest('created_at')->firstOrFail());
    expect($run->status)->toBe(ImportRun::COMMITTED)->and($run->payload_ciphertext)->toBeNull();
});

it('reads the temporary upload once, seals it and deletes it in the same request', function (): void {
    Storage::fake('local');
    $file = UploadedFile::fake()->createWithContent('Slepens saraksts.csv', $this->csv);

    $page = inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(RosterImportPage::class)
        ->set('upload', $file)
        ->call('uploadFile')
        ->assertSet('upload', null));

    expect(Storage::disk('local')->allFiles('livewire-tmp'))->toBe([])
        ->and(json_encode($page->snapshot))->not->toContain('Slepens');

    $run = inBuilding($this->b, fn () => ImportRun::query()->latest('created_at')->firstOrFail());
    expect($run->payload_ciphertext)->not->toBeNull()
        ->and($run->payload_ciphertext)->not->toContain('Slepens')
        ->and(json_encode($run->toArray()))->not->toContain('Slepens')->not->toContain('saraksts'); // no file name either
});

it('never puts a value into an exception message or a commit failure report', function (): void {
    try {
        app(CsvReader::class)->parse('1;'.str_repeat('Slepens', 200)."\n");
    } catch (ImportRefused $e) {
        expect($e->getMessage())->toBe('import refused: cell_too_long');
    }

    // A commit that fails reports the exception CLASS only and keeps nothing.
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported): bool {
        $reported[] = $e->getMessage();

        return false;
    });
    $run = uploadCsv($this->b, $this->admin, $this->csv);
    inBuilding($this->b, fn () => app(ImportRunService::class)->map($this->admin, $run, $run->mapping, ImportContext::CREATE_ONLY));
    inBuilding($this->b, fn () => app(ImportRunService::class)->dryRun($this->admin, $run->refresh()));
    inBuilding($this->b, fn () => ImportRun::query()->whereKey($run->id)->update(['status' => 'committing', 'options' => json_encode(['mode' => 'create_only', 'committed_by' => 0])]));
    inBuilding($this->b, fn () => app(ImportRunService::class)->runCommit($run->id));

    expect($run->refresh()->status)->toBe(ImportRun::FAILED)
        ->and($run->payload_ciphertext)->toBeNull()
        ->and($run->error_code)->toBe('commit_failed')
        ->and(implode(' ', $reported))->not->toContain('Slepens');
});

it('redacts paste / csv / payload keys from logs and GlitchTip request data', function (): void {
    $scrubbed = RedactSecrets::scrub(['components' => [['updates' => ['paste' => 'Slepens;'.$this->secretPhone, 'csv' => 'x', 'page' => 2]]]]);

    expect(json_encode($scrubbed))->not->toContain('Slepens')
        ->and($scrubbed['components'][0]['updates']['paste'])->toBe('[redacted]')
        ->and($scrubbed['components'][0]['updates']['page'])->toBe(2);
});

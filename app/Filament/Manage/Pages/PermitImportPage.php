<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Auth\StepUpService;
use App\Enums\ImportKind;
use App\Enums\ImportStatus;
use App\Gdpr\ParkingGate;
use App\Models\ImportRun;
use App\Models\ParkingLot;
use App\Models\User;
use App\Services\Import\ColumnMapper;
use App\Services\Import\CsvReader;
use App\Services\Import\ImportFailed;
use App\Services\Import\ImportHandler;
use App\Services\Import\ImportRunService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Permit issuer's list import (PARK-012, ROUND2-SPEC §6.3) on the shared pipeline: 1 Augšupielāde → 2 Kartēšana →
 * 3 Pārbaude → 4 Izmēģinājums → 5 Imports, resumable for 24 h.
 *
 * Below parking gate L1 the page (reachable by parking staff from the status page) renders the explanation card with
 * the missing items instead of the uploader, and every action refuses server-side (D6) — even a forged Livewire call.
 * No cell value ever sits in a public property: the run id is #[Locked], the pasted text travels as an action argument
 * (never wire:model), preview rows come from the sealed payload per request, masked (plates `AB••34`).
 * Commit: dry-run younger than 30 min, the issuer-endorsement checkbox and a fresh MFA step-up.
 */
final class PermitImportPage extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 56;

    protected static ?string $slug = 'permit-import';

    protected string $view = 'filament.manage.pages.permit-import';

    #[Locked]
    public ?string $runId = null;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    /** @var array<int|string, string|null> column index => target ('' = not imported) */
    public array $columns = [];

    public string $mode = 'create_only';

    public string $encoding = 'utf-8';

    public string $delimiter = ';';

    public bool $hasHeader = true;

    public bool $remap = false;

    public int $page = 1;

    public bool $onlyErrors = false;

    public bool $endorsed = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.parking.import.title');
    }

    public function getTitle(): string
    {
        return __('admin.parking.import.title');
    }

    /**
     * Parking staff at any level: below L1 the page is the explanation card (R2 — never a silent 403).
     */
    public static function canAccess(): bool
    {
        return Gate::allows('create', ParkingLot::class);
    }

    /**
     * Navigation shows the import only from L1 (ROUND2-SPEC §1.2); the status page links here below it.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (ParkingGate::current()?->l1() ?? false) && Gate::allows('parking-import');
    }

    public function mount(ImportRunService $imports): void
    {
        abort_unless(self::canAccess(), 403);

        if ($this->gateOpen() && Gate::allows('parking-import')) {
            $run = $imports->resumable($this->user(), ImportKind::Permits);
            if ($run !== null) {
                $this->load($run);
            }
        }
    }

    public function gateOpen(): bool
    {
        return $this->handler()->gateOpen();
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        return $this->handler()->missingGateItems();
    }

    public function wizardUrl(): string
    {
        $wizard = 'App\\Filament\\Manage\\Pages\\HouseOpeningWizardPage';

        return class_exists($wizard) ? $wizard::getUrl().'#step-parking_scope' : ParkingSignPage::getUrl();
    }

    public function run(): ?ImportRun
    {
        if ($this->runId === null) {
            return null;
        }

        $run = ImportRun::query()->where('kind', ImportKind::Permits->value)->find($this->runId);

        return $run !== null && Gate::allows('view', $run) ? $run : null;
    }

    /**
     * 1 upload · 2 mapping · 3 preview · 4 dry-run · 5 commit.
     */
    public function step(): int
    {
        $run = $this->run();

        return match (true) {
            $run === null => 1,
            $run->status === ImportStatus::Uploaded || $this->remap => 2,
            $run->status === ImportStatus::Mapped => 3,
            $run->status === ImportStatus::Validated => 4,
            default => 5,
        };
    }

    public function uploadFile(ImportRunService $imports): void
    {
        $this->guard();
        $this->validate(['upload' => ['required', 'file', 'max:'.(CsvReader::MAX_BYTES / 1024)]]);

        /** @var TemporaryUploadedFile $file */
        $file = $this->upload;
        $path = $file->getRealPath();

        try {
            app(CsvReader::class)->assertUpload((string) $path, $file->getClientOriginalName());
            $bytes = (string) file_get_contents((string) $path);
        } catch (ImportFailed $e) {
            $this->dropUpload();
            Notification::make()->danger()->title($e->label())->send();

            return;
        }

        $this->dropUpload();
        $this->start($imports, $bytes);
    }

    /**
     * Pasted CSV arrives as an action argument (never a public property).
     */
    public function uploadPaste(ImportRunService $imports, string $text): void
    {
        $this->guard();

        if (trim($text) === '' || strlen($text) > CsvReader::MAX_BYTES) {
            Notification::make()->danger()->title(__('admin.import.error.empty'))->send();

            return;
        }

        $this->start($imports, $text);
    }

    public function saveFormat(ImportRunService $imports): void
    {
        $run = $this->guardedRun();
        if ($run === null) {
            return;
        }

        $this->attempt(fn () => $this->load($imports->configure($run, $this->encoding, $this->delimiter, $this->hasHeader)));
        $this->remap = true;
    }

    public function saveMapping(ImportRunService $imports): void
    {
        $run = $this->guardedRun();
        if ($run === null) {
            return;
        }

        $problems = app(ColumnMapper::class)->problems(array_filter($this->columns, fn ($t): bool => is_string($t) && $t !== ''), $this->handler());
        if ($problems !== []) {
            Notification::make()->danger()->title(__('admin.import.error.mapping'))
                ->body(implode(' · ', array_map(fn (string $p): string => $this->problemLabel($p), $problems)))->send();

            return;
        }

        if ($this->attempt(fn () => $imports->map($this->user(), $run, $this->columns, $this->mode))) {
            $this->remap = false;
            $this->page = 1;
        }
    }

    public function editMapping(): void
    {
        $this->remap = true;
    }

    public function dryRun(ImportRunService $imports): void
    {
        $run = $this->guardedRun();
        if ($run !== null) {
            $this->attempt(fn () => $imports->dryRun($run));
        }
    }

    public function commit(ImportRunService $imports): void
    {
        $run = $this->guardedRun();
        if ($run === null) {
            return;
        }

        $user = $this->user();
        $stepUp = app(StepUpService::class);
        if (! $stepUp->hasFreshMfa($user)) {
            $this->redirect($stepUp->ask('mfa', session()->previousUrl()));

            return;
        }

        if (! $this->endorsed) {
            Notification::make()->warning()->title(__('admin.parking.import.endorse_first'))->send();

            return;
        }

        if ($this->attempt(fn () => $imports->commit($user, $run, $this->endorsed))) {
            $this->endorsed = false;
        }
    }

    public function discard(ImportRunService $imports): void
    {
        $run = $this->run();
        if ($run !== null && ! $run->status->isTerminal()) {
            $this->attempt(fn () => $imports->discard($this->user(), $run));
        }
        $this->reset(['runId', 'columns', 'remap', 'page', 'onlyErrors', 'endorsed', 'mode']);
    }

    public function startOver(): void
    {
        $this->reset(['runId', 'columns', 'remap', 'page', 'onlyErrors', 'endorsed', 'mode']);
    }

    public function setPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function updatedOnlyErrors(): void
    {
        $this->page = 1;
    }

    /**
     * Column labels (header text — not personal data) for the mapping step.
     *
     * @return list<string>
     */
    public function headerLabels(): array
    {
        $run = $this->run();

        return $run !== null && ! $run->status->isTerminal() ? app(ImportRunService::class)->header($run) : [];
    }

    /**
     * @return array<string, array{label: string, required: bool}>
     */
    public function targets(): array
    {
        $out = [];
        foreach ($this->handler()->targets() as $target => $spec) {
            $out[$target] = ['label' => __('admin.parking.import.target.'.$target), 'required' => (bool) $spec['required']];
        }

        return $out;
    }

    /**
     * @return array{rows: list<array{line: int, status: string, codes: list<string>, cells: array<string, string>}>, page: int, pages: int, counts: array<string, mixed>}|null
     */
    public function preview(): ?array
    {
        $run = $this->run();
        if ($run === null || ! in_array($run->status, [ImportStatus::Mapped, ImportStatus::Validated], true)) {
            return null;
        }

        try {
            return app(ImportRunService::class)->preview($run, $this->page, $this->onlyErrors);
        } catch (ImportFailed) {
            return null;
        }
    }

    public function sameFile(): ?ImportRun
    {
        $run = $this->run();

        return $run !== null ? app(ImportRunService::class)->sameFileCommitted($run) : null;
    }

    public function problemLabel(string $problem): string
    {
        if (str_starts_with($problem, 'required_missing:')) {
            return __('admin.import.required_target', ['target' => __('admin.parking.import.target.'.substr($problem, 17))]);
        }

        return __('admin.import.error.'.$problem);
    }

    private function start(ImportRunService $imports, string $bytes): void
    {
        $this->attempt(function () use ($imports, $bytes): void {
            $run = $imports->upload($this->user(), ImportKind::Permits, $bytes);
            $this->load($run);
            $this->remap = false;
            $this->page = 1;
        });
    }

    private function load(ImportRun $run): void
    {
        $this->runId = (string) $run->id;
        $this->encoding = $run->encoding;
        $this->delimiter = $run->delimiter;
        $this->hasHeader = $run->has_header;
        $this->mode = $run->mode();
        $this->columns = [];
        foreach ($run->mapping ?? [] as $index => $target) {
            $this->columns[(int) $index] = (string) $target;
        }
    }

    /**
     * Gate + policy before every write (D6) — a forged call below L1 never reaches the pipeline.
     */
    private function guard(): void
    {
        abort_unless(self::canAccess(), 403);

        if (! $this->gateOpen() || ! Gate::allows('create', [ImportRun::class, ImportKind::Permits])) {
            abort(403);
        }
    }

    private function guardedRun(): ?ImportRun
    {
        $this->guard();
        $run = $this->run();

        if ($run === null) {
            $this->startOver();
            Notification::make()->warning()->title(__('admin.import.error.expired'))->send();
        }

        return $run;
    }

    /**
     * Run a pipeline step; ImportFailed → a translated notification (keys only). true on success.
     */
    private function attempt(callable $step): bool
    {
        try {
            $step();
        } catch (ImportFailed $e) {
            Notification::make()->danger()->title($e->label())->send();
            if (in_array($e->key, ['expired', 'payload_gone'], true)) {
                $this->startOver();
            }

            return false;
        }

        return true;
    }

    private function dropUpload(): void
    {
        if ($this->upload instanceof TemporaryUploadedFile) {
            $this->upload->delete();
        }
        $this->upload = null;
    }

    private function handler(): ImportHandler
    {
        return app(ImportRunService::class)->handler(ImportKind::Permits);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}

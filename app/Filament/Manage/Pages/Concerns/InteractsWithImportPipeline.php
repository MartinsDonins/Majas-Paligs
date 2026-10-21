<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages\Concerns;

use App\Auth\StepUpService;
use App\Filament\Manage\Pages\HouseOpeningWizardPage;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportField;
use App\Services\Import\ImportHandler;
use App\Services\Import\ImportRefused;
use App\Services\Import\ImportRunService;
use App\Services\Import\RowResult;
use App\Services\Import\RowValidator;
use App\Tenancy\CurrentBuilding;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The 5-step CSV import UI (DB-003, ROUND2-SPEC §4.1) for a Filament page of one import kind:
 * Upload → Kartēšana → Pārbaude → Izmēģinājums → Imports. Used by RosterImportPage (`roster`) and — PARK-012 —
 * PermitImportPage (`permits`) with the shared view `filament.manage.pages.import-pipeline`.
 *
 * Privacy (AC-R2-19): no cell value is ever a public Livewire property — only the #[Locked] run id, the mapping (field
 * keys), the page number, the filter and the confirmations. The paste box is cleared in the same request it is read;
 * the temporary upload is read once, sealed and deleted. The Livewire upload URL itself is refused below the gate
 * (`_startUpload`), so a forged upload never stores a file. Every action re-checks role + gate server-side.
 *
 * The using page implements `importKind()`.
 */
trait InteractsWithImportPipeline
{
    use WithFileUploads {
        _startUpload as protected livewireStartUpload;
    }

    #[Locked]
    public ?string $runId = null;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $paste = '';

    public string $encoding = '';

    public string $delimiter = '';

    public bool $hasHeader = true;

    /** @var array<int|string, string|null> column index => field key */
    public array $mapping = [];

    public string $mode = ImportContext::CREATE_ONLY;

    public int $previewPage = 1;

    public bool $onlyErrors = false;

    /** @var array<string, bool> confirmation key => ticked */
    public array $confirmed = [];

    abstract protected static function importKind(): string;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Page access = the kind's role (below the gate the page explains what is missing instead of the uploader).
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || CurrentBuilding::find() === null) {
            return false;
        }

        try {
            return app(ImportRunService::class)->handler(static::importKind())->mayImport($user);
        } catch (ImportRefused) {
            return false;
        }
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->gateMissing() !== null) {
            return;
        }

        // Resume the newest open run of this kind (24 h).
        $run = ImportRun::query()->ofKind(static::importKind())
            ->whereIn('status', [...ImportRun::OPEN, ImportRun::COMMITTING])
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->orderByDesc('id')
            ->first();

        if ($run !== null) {
            $this->runId = (string) $run->id;
            $this->syncFromRun($run);
        }
    }

    /**
     * Livewire asks for a signed upload URL BEFORE the file is sent: refuse it below the gate / without the role (D6).
     *
     * @param  mixed  $name
     * @param  mixed  $fileInfo
     * @param  mixed  $isMultiple
     */
    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        try {
            app(ImportRunService::class)->assertAllowed($this->importUser(), static::importKind());
        } catch (ImportRefused $e) {
            $this->refused($e);

            abort(403);
        }

        $this->livewireStartUpload($name, $fileInfo, $isMultiple);
    }

    public function uploadFile(): void
    {
        $file = $this->upload;
        $this->upload = null;

        if (! $file instanceof TemporaryUploadedFile) {
            Notification::make()->warning()->title(__('import.upload.choose_file'))->send();

            return;
        }

        try {
            $run = app(ImportRunService::class)->uploadFile($this->importUser(), static::importKind(), $file->getRealPath(), $file->getClientOriginalName());
            $this->started($run);
        } catch (ImportRefused $e) {
            $this->refused($e);
        } finally {
            $file->delete();
        }
    }

    public function uploadPaste(): void
    {
        $text = $this->paste;
        $this->paste = '';

        if (trim($text) === '') {
            Notification::make()->warning()->title(__('import.error.file_empty'))->send();

            return;
        }

        try {
            $this->started(app(ImportRunService::class)->upload($this->importUser(), static::importKind(), $text));
        } catch (ImportRefused $e) {
            $this->refused($e);
        }
    }

    public function reformat(): void
    {
        $this->act(function (ImportRunService $imports, ImportRun $run): void {
            $run = $imports->reformat($this->importUser(), $run, $this->encoding ?: null, $this->delimiter ?: null, $this->hasHeader);
            $this->syncFromRun($run);
        });
    }

    public function saveMapping(): void
    {
        $this->act(function (ImportRunService $imports, ImportRun $run): void {
            $imports->map($this->importUser(), $run, $this->mapping, $this->mode);
            $this->previewPage = 1;
        });
    }

    public function backToMapping(): void
    {
        $this->act(function (ImportRunService $imports, ImportRun $run): void {
            $imports->reformat($this->importUser(), $run, null, null, null);
            $this->syncFromRun($run->refresh());
        });
    }

    public function dryRun(): void
    {
        $this->act(function (ImportRunService $imports, ImportRun $run): void {
            $imports->dryRun($this->importUser(), $run);
        });
    }

    public function commitImport(): void
    {
        $user = $this->importUser();

        if (! app(StepUpService::class)->hasFreshMfa($user)) {
            $this->redirect(app(StepUpService::class)->ask('mfa', session()->previousUrl()));

            return;
        }

        $this->act(function (ImportRunService $imports, ImportRun $run) use ($user): void {
            $imports->commit($user, $run, array_keys(array_filter($this->confirmed)));
        });
    }

    public function discardRun(): void
    {
        $this->act(function (ImportRunService $imports, ImportRun $run): void {
            if (in_array($run->status, ImportRun::OPEN, true)) {
                $imports->discard($this->importUser(), $run);
            }
        });
        $this->resetPipeline();
    }

    public function startOver(): void
    {
        $this->resetPipeline();
    }

    public function goToPage(int $page): void
    {
        $this->previewPage = max(1, $page);
    }

    public function updatedOnlyErrors(): void
    {
        $this->previewPage = 1;
    }

    // ---- read by the view (never stored in public properties) ----

    /**
     * @return list<string>|null missing gate conditions (null = open)
     */
    public function gateMissing(): ?array
    {
        try {
            return $this->importHandler()->closedGate();
        } catch (Throwable) {
            return [];
        }
    }

    public function importHandler(): ImportHandler
    {
        return app(ImportRunService::class)->handler(static::importKind());
    }

    public function currentRun(): ?ImportRun
    {
        return $this->runId === null ? null : ImportRun::query()->ofKind(static::importKind())->whereKey($this->runId)->first();
    }

    /**
     * @return array<string, ImportField>
     */
    public function importFields(): array
    {
        return $this->importHandler()->fields();
    }

    /**
     * @return array{headers: list<string>, samples: array<int, list<string>>, previous: ?ImportRun}
     */
    public function mappingData(ImportRun $run): array
    {
        $imports = app(ImportRunService::class);

        return ['headers' => $imports->headers($run), 'samples' => $imports->samples($run), 'previous' => $imports->previousImport($run)];
    }

    /**
     * @return array{rows: list<RowResult>, total: int, pages: int, page: int, severity: array<string, string>}|null
     */
    public function previewData(ImportRun $run): ?array
    {
        try {
            $data = app(ImportRunService::class)->preview($this->importUser(), $run, $this->previewPage, $this->onlyErrors);
        } catch (ImportRefused) {
            return null;
        }

        return $data + ['severity' => RowValidator::severity($this->importHandler())];
    }

    public function templateUrl(): string
    {
        return route('admin.import-template', ['building' => CurrentBuilding::get()->slug, 'kind' => static::importKind()]);
    }

    public function gateUrl(): string
    {
        return HouseOpeningWizardPage::getUrl();
    }

    // ---- internals ----

    private function started(ImportRun $run): void
    {
        $this->runId = (string) $run->id;
        $this->previewPage = 1;
        $this->confirmed = [];
        $this->syncFromRun($run);
        Notification::make()->success()->title(__('import.upload.done', ['rows' => $run->row_count]))->send();
    }

    private function syncFromRun(ImportRun $run): void
    {
        $this->encoding = $run->encoding;
        $this->delimiter = $run->delimiter;
        $this->hasHeader = $run->has_header;
        $this->mode = (string) ($run->options['mode'] ?? ImportContext::CREATE_ONLY);
        $this->mapping = [];
        foreach ($run->mapping as $index => $field) {
            $this->mapping[(string) $index] = (string) $field;
        }
    }

    /**
     * @param  callable(ImportRunService, ImportRun): void  $callback
     */
    private function act(callable $callback): void
    {
        $run = $this->currentRun();
        if ($run === null) {
            $this->resetPipeline();
            Notification::make()->warning()->title(__('import.error.run_expired'))->send();

            return;
        }

        try {
            $callback(app(ImportRunService::class), $run);
        } catch (ImportRefused $e) {
            $this->refused($e);
        }
    }

    private function refused(ImportRefused $e): void
    {
        $note = Notification::make()->danger()->title($e->label());
        if ($e->missing !== []) {
            $note->body(implode(' · ', $e->missing));
        }
        $note->send();
    }

    private function resetPipeline(): void
    {
        $this->runId = null;
        $this->upload = null;
        $this->paste = '';
        $this->mapping = [];
        $this->mode = ImportContext::CREATE_ONLY;
        $this->previewPage = 1;
        $this->onlyErrors = false;
        $this->confirmed = [];
    }

    private function importUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}

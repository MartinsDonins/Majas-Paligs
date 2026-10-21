<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\Locale;
use App\Filament\Manage\Resources\ApartmentResource;
use App\Models\Building;
use App\Models\BuildingDocument;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Documents\DocumentRefused;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DpiaLite;
use App\Services\Opening\HouseOpeningService;
use App\Services\Opening\OpeningChecklist;
use App\Support\BuildingSettings;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Mājas atvēršana" — the house opening wizard (ROUND2-SPEC §3.2, GOV-010, AC-R2-08): a vertical checklist, not a
 * modal stepper, so it survives interruptions over days. One card per step with status (icon + word), "Kāpēc?",
 * "Kas to dara?" and one action; progress per gate; deep link #step-<key> (the dashboard cards link here).
 *
 * View: any manage role (elders read everything, parking officers the parking steps). Every action re-checks
 * building_admin (BuildingPolicy::update / BuildingDocumentPolicy) and asks for a fresh second factor, is audited
 * (`opening.*`, `document.*`) and runs the gate monitors (HouseOpeningService / DocumentService). The DPA itself is
 * recorded by CoreDigify from "Atvēršanas pieprasījumi" — this page can only prepare, finalise and send it.
 */
final class HouseOpeningWizardPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'opening';

    protected string $view = 'filament.manage.pages.house-opening';

    /** @var array{name: ?string, contact: ?string, basis_ref: ?string} */
    public array $controller = ['name' => null, 'contact' => null, 'basis_ref' => null];

    /** @var list<string> modules requested for the DPA / annex */
    public array $dpaScope = ['core', 'heating', 'outages'];

    /** @var list<string> modules the next notice covers */
    public array $noticeModules = ['core', 'heating', 'outages'];

    public ?string $mandateRef = null;

    public ?string $rosterInformedAt = null;

    /** @var array<string, array{answer: ?string, note: string, accepted?: bool}> */
    public array $answers = [];

    /** The house document open in the editor (DocumentEditor child). */
    #[Locked]
    public ?string $editingId = null;

    /** @var list<string> person-facing linter / refusal lines of the last finalise attempt */
    #[Locked]
    public array $problems = [];

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.governance');
    }

    public static function getNavigationLabel(): string
    {
        return __('opening.title');
    }

    public function getTitle(): string
    {
        return __('opening.title');
    }

    public function getSubheading(): string
    {
        return __('opening.lead');
    }

    public static function canAccess(): bool
    {
        $building = CurrentBuilding::find();
        $user = auth()->user();

        return $building !== null && $user instanceof User && ! $user->isTombstone()
            && ($user->hasRoleIn($building, BuildingRoleType::BuildingAdmin)
                || $user->hasRoleIn($building, BuildingRoleType::SectionElder)
                || $user->hasRoleIn($building, BuildingRoleType::ParkingOfficer));
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->fillFromSettings();
    }

    public function canEdit(): bool
    {
        return Gate::allows('update', CurrentBuilding::get());
    }

    /**
     * A parking officer (without another manage role) reads the parking steps only (§1.2).
     */
    public function parkingOnly(): bool
    {
        $user = auth()->user();
        $b = CurrentBuilding::get();

        return $user instanceof User && ! $user->hasRoleIn($b, BuildingRoleType::BuildingAdmin)
            && ! $user->hasRoleIn($b, BuildingRoleType::SectionElder);
    }

    public function checklist(): OpeningChecklist
    {
        return OpeningChecklist::for(CurrentBuilding::get());
    }

    public function building(): Building
    {
        return CurrentBuilding::get();
    }

    /**
     * Newest non-superseded document of a kind (null when none was prepared yet).
     */
    public function document(DocumentKind $kind): ?BuildingDocument
    {
        return BuildingDocument::latestOf($kind);
    }

    /**
     * The newest agreement (DPA or annex) that is not yet recorded — what the DPA / scope cards talk about.
     */
    public function openAgreement(): ?BuildingDocument
    {
        return BuildingDocument::query()->whereIn('kind', [DocumentKind::Dpa->value, DocumentKind::DpaAnnex->value])
            ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::Final->value, DocumentStatus::Sent->value])
            ->orderByDesc('created_at')->first();
    }

    public function editing(): ?BuildingDocument
    {
        return $this->editingId === null ? null : BuildingDocument::query()->find($this->editingId);
    }

    /**
     * @return array<string, string> module => label, for the scope / notice checkboxes
     */
    public function moduleOptions(): array
    {
        return collect(BuildingSettings::DPA_SCOPES)->mapWithKeys(fn (string $m): array => [$m => __('enum.module.'.$m)])->all();
    }

    // ---- step actions (building_admin + fresh second factor; the services are the backstop) ----

    public function saveController(): void
    {
        $this->validate([
            'controller.name' => ['required', 'string', 'max:200'],
            'controller.contact' => ['required', 'string', 'max:200'],
            'controller.basis_ref' => ['nullable', 'string', 'max:200'],
        ], attributes: [
            'controller.name' => __('opening.controller.name'),
            'controller.contact' => __('opening.controller.contact'),
        ]);

        $this->act(fn (User $u) => app(HouseOpeningService::class)->saveController($u, $this->controller), __('opening.saved'));
    }

    /**
     * Prepare the DPA (no DPA recorded yet) or an annex extending the recorded scope with $module.
     */
    public function prepareAgreement(?string $module = null): void
    {
        $recorded = CurrentBuilding::get()->settings;
        $scope = $module === null ? $this->dpaScope : [...$recorded->dpaScope, ...$this->dpaScope, $module];
        $kind = $recorded->dpaSignedAt !== null ? DocumentKind::DpaAnnex : DocumentKind::Dpa;

        $this->act(function (User $u) use ($kind, $scope): void {
            $document = app(DocumentService::class)->prepare($u, $kind, ['requested_scope' => array_values(array_unique($scope))]);
            $this->editingId = $document->id;
        }, __('opening.document.prepared'));
    }

    /**
     * Prepare the next notice version covering $noticeModules (+ $module for the roster / parking steps).
     */
    public function prepareNotice(?string $module = null): void
    {
        $modules = array_values(array_unique([...$this->noticeModules, ...($module !== null ? [$module] : [])]));

        $this->act(function (User $u) use ($modules): void {
            $document = app(DocumentService::class)->prepare($u, DocumentKind::PrivacyNotice, ['covers_modules' => $modules]);
            $this->editingId = $document->id;
            $this->noticeModules = $document->coversModules();
        }, __('opening.document.prepared'));
    }

    public function prepareDpia(): void
    {
        $this->act(function (User $u): void {
            $document = app(DocumentService::class)->prepare($u, DocumentKind::DpiaLite);
            $this->editingId = $document->id;
            $this->answers = DpiaLite::normalise((array) $document->answers);
        }, __('opening.document.prepared'));
    }

    public function edit(string $id): void
    {
        $document = BuildingDocument::query()->findOrFail($id);
        Gate::authorize('view', $document);
        $this->editingId = $document->id;
        $this->problems = [];
        if ($document->kind === DocumentKind::DpiaLite) {
            $this->answers = DpiaLite::normalise((array) $document->answers);
        }
    }

    public function closeEditor(): void
    {
        $this->editingId = null;
        $this->problems = [];
    }

    #[On('document-saved')]
    public function documentSaved(): void
    {
        $this->problems = [];
    }

    /**
     * "Gatavs parakstīšanai" (DPA / annex) — lint and freeze.
     */
    public function finalize(string $id): void
    {
        $this->act(fn (User $u) => app(DocumentService::class)->finalize($u, $this->documentOrFail($id)), __('opening.document.finalized'));
    }

    /**
     * "Nosūtīt CoreDigify" — the card then reads "Gaida CoreDigify".
     */
    public function send(string $id): void
    {
        $this->act(fn (User $u) => app(DocumentService::class)->send($u, $this->documentOrFail($id)), __('opening.document.sent'));
    }

    public function publishNotice(string $id): void
    {
        $this->act(function (User $u) use ($id): void {
            app(DocumentService::class)->publishNotice($u, $this->documentOrFail($id));
            $this->editingId = null;
        }, __('opening.document.published'));
    }

    public function saveAnswers(): void
    {
        if ($this->editingId === null) {
            return;
        }
        $this->act(fn (User $u) => app(DocumentService::class)->saveAnswers($u, $this->documentOrFail($this->editingId), $this->answers), __('opening.saved'));
    }

    public function completeDpia(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $this->act(function (User $u): void {
            $service = app(DocumentService::class);
            $document = $service->saveAnswers($u, $this->documentOrFail((string) $this->editingId), $this->answers);
            $service->completeDpia($u, $document);
            $this->editingId = null;
        }, __('opening.dpia.completed'));
    }

    public function saveMandate(): void
    {
        $this->validate(['mandateRef' => ['required', 'string', 'max:200']], attributes: ['mandateRef' => __('opening.mandate.label')]);
        $this->act(fn (User $u) => app(HouseOpeningService::class)->saveMandate($u, $this->mandateRef), __('opening.saved'));
    }

    public function markRosterInformed(): void
    {
        $this->act(fn (User $u) => app(HouseOpeningService::class)->markRosterInformed($u, $this->rosterInformedAt ?? now('Europe/Riga')->toDateString()), __('opening.saved'));
    }

    public function setParking(bool $on): void
    {
        $this->act(fn (User $u) => app(HouseOpeningService::class)->setParkingModule($u, $on), __('opening.saved'));
    }

    /**
     * PDF of a house document (admin; officer for DPIA-lite) in one language.
     */
    public function downloadPdf(string $id, string $locale): StreamedResponse
    {
        $document = BuildingDocument::query()->findOrFail($id);
        Gate::authorize('view', $document);
        $locale = $locale === 'ru' ? 'ru' : 'lv';
        $building = CurrentBuilding::get();

        return DocumentRenderer::download(
            app(DocumentRenderer::class)->pdfBytes($document, $building, $locale),
            DocumentRenderer::filename($building, $document->kind->value, $document->version, $locale),
        );
    }

    /**
     * The neutral parking decision template (GOV-006), LV or RU — download only, never "binding".
     */
    public function downloadDecision(string $locale): ?StreamedResponse
    {
        abort_unless(Gate::allows('viewAny', BuildingDocument::class), 403);
        $template = DocumentTemplate::latest(DocumentKind::ParkingDecision, $locale === 'ru' ? Locale::Ru : Locale::Lv);
        if ($template === null) {
            Notification::make()->warning()->title(__('document.error.template_missing'))->send();

            return null;
        }
        $building = CurrentBuilding::get();

        return DocumentRenderer::download(
            app(DocumentRenderer::class)->templatePdfBytes($template, $building),
            DocumentRenderer::filename($building, 'parking_decision', $template->version, $template->locale->value),
        );
    }

    public function structureUrl(): ?string
    {
        return ApartmentResource::canViewAny() ? ApartmentResource::getUrl('index', tenant: CurrentBuilding::get()) : null;
    }

    public function teamUrl(): string
    {
        return TeamPage::getUrl(tenant: CurrentBuilding::get());
    }

    public function invitesUrl(): ?string
    {
        return InviteBatchPage::canAccess() ? InviteBatchPage::getUrl(tenant: CurrentBuilding::get()) : null;
    }

    // ---- internals ----

    private function fillFromSettings(): void
    {
        $s = CurrentBuilding::get()->settings;
        $this->controller = $s->controller;
        $this->mandateRef = $s->mandateRef;
        $this->rosterInformedAt = $s->rosterInformedAt ?? now('Europe/Riga')->toDateString();

        $requested = $this->openAgreement()?->requestedScope();
        $this->dpaScope = $requested ?? array_values(array_unique([...['core', 'heating', 'outages'], ...$s->dpaScope]));

        $notice = BuildingDocument::latestOf(DocumentKind::PrivacyNotice);
        $this->noticeModules = $notice?->coversModules() ?: array_values(array_intersect(['core', 'heating', 'outages', 'parking', 'roster'], [...$s->dpaScope, 'core', 'heating', 'outages']));
    }

    private function documentOrFail(string $id): BuildingDocument
    {
        return BuildingDocument::query()->findOrFail($id);
    }

    /**
     * Run a step with the second factor demanded first; refusals become calm notifications (never an error page).
     *
     * @param  callable(User): mixed  $callback
     */
    private function act(callable $callback, string $success): void
    {
        /** @var User $user */
        $user = auth()->user();

        if (! Gate::allows('update', CurrentBuilding::get())) {
            Notification::make()->danger()->title(__('opening.error.admin_only'))->send();

            return;
        }

        $stepUp = app(StepUpService::class);
        if (! $stepUp->hasFreshMfa($user)) {
            $this->redirect($stepUp->ask('mfa', session()->previousUrl()));

            return;
        }

        $this->problems = [];

        try {
            $callback($user);
        } catch (DocumentRefused $e) {
            $this->problems = $e->lines();
            Notification::make()->warning()->title($e->getMessage())
                ->body($this->problems === [] ? null : implode("\n", array_slice($this->problems, 0, 6)))->send();

            return;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title(__('opening.error.step_up'))->send();

            return;
        }

        $this->fillFromSettings();
        Notification::make()->success()->title($success)->send();
    }
}

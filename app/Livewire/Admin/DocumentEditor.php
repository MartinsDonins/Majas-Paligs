<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Auth\StepUpService;
use App\Models\BuildingDocument;
use App\Models\User;
use App\Services\Documents\DocumentRefused;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\DocumentService;
use App\Services\Documents\TemplateMarkup;
use App\Tenancy\CurrentBuilding;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * DocumentEditor (ROUND2-SPEC §3.4) inside the opening wizard: locked blocks as grey read-only cards, editable blocks
 * as textareas (≤ 4000 characters), LV and RU side by side on ≥ 1024 px and as tabs on phones, plus a rendered
 * preview. Only editable keys are ever sent to DocumentService::saveBlocks — a forged key for a locked block is
 * refused there. The bodies contain no personal data (TemplateLinter), so the texts may live in the snapshot.
 */
final class DocumentEditor extends Component
{
    #[Locked]
    public string $documentId;

    /** @var array<string, string> */
    public array $lv = [];

    /** @var array<string, string> */
    public array $ru = [];

    public bool $preview = false;

    public function mount(string $documentId): void
    {
        $document = BuildingDocument::query()->findOrFail($documentId);
        Gate::authorize('view', $document);

        $this->documentId = $document->id;
        $this->lv = $this->editableOf($document->body_lv);
        $this->ru = $this->editableOf($document->body_ru);
    }

    public function save(): void
    {
        $document = $this->document();
        /** @var User $user */
        $user = auth()->user();

        $stepUp = app(StepUpService::class);
        if (! $stepUp->hasFreshMfa($user)) {
            $this->redirect($stepUp->ask('mfa', session()->previousUrl()));

            return;
        }

        try {
            app(DocumentService::class)->saveBlocks($user, $document, $this->lv, $this->ru);
        } catch (DocumentRefused $e) {
            Notification::make()->warning()->title($e->getMessage())->send();

            return;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title(__('opening.error.admin_only'))->send();

            return;
        }

        Notification::make()->success()->title(__('opening.document.saved'))->send();
        $this->dispatch('document-saved');
    }

    public function togglePreview(): void
    {
        $this->preview = ! $this->preview;
    }

    public function render(): View
    {
        $document = $this->document();
        $renderer = app(DocumentRenderer::class);
        $building = CurrentBuilding::get();

        return view('filament.manage.document-editor', [
            'document' => $document,
            'canEdit' => $document->isDraft() && Gate::allows('update', $document),
            'segments' => ['lv' => $this->segmentsOf($document->body_lv), 'ru' => $this->segmentsOf($document->body_ru)],
            'html' => $this->preview ? ['lv' => $renderer->documentHtml($document, $building, 'lv'), 'ru' => $renderer->documentHtml($document, $building, 'ru')] : null,
            'max' => DocumentService::MAX_BLOCK_CHARS,
        ]);
    }

    private function document(): BuildingDocument
    {
        $document = BuildingDocument::query()->findOrFail($this->documentId);
        Gate::authorize('view', $document);

        return $document;
    }

    /**
     * @return array<string, string>
     */
    private function editableOf(string $body): array
    {
        try {
            return TemplateMarkup::editable($body);
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * @return list<array{type: string, key: string, text: string}>
     */
    private function segmentsOf(string $body): array
    {
        try {
            return array_values(array_filter(TemplateMarkup::parse($body), static fn (array $s): bool => trim($s['text']) !== '' || $s['type'] === 'edit'));
        } catch (InvalidArgumentException) {
            return [['type' => 'text', 'key' => 'text-1', 'text' => $body]];
        }
    }
}

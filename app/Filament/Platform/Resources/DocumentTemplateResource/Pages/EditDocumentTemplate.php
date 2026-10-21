<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\DocumentTemplateResource\Pages;

use App\Filament\Platform\Resources\DocumentTemplateResource;
use App\Models\DocumentTemplate;
use App\Services\Documents\TemplateMarkup;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Edit a DRAFT master version only (a published one is immutable — policy, and a DB trigger behind it). The markers
 * must parse before the draft is saved.
 */
final class EditDocumentTemplate extends EditRecord
{
    protected static string $resource = DocumentTemplateResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var DocumentTemplate $template */
        $template = $this->record;
        abort_unless(Gate::allows('update', $template), 403);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        try {
            TemplateMarkup::parse((string) ($data['body'] ?? ''));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['data.body' => __('document.lint.markers_broken')]);
        }

        return $data;
    }
}

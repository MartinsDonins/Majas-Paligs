<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\DocumentTemplateResource\Pages;

use App\Filament\Platform\Resources\DocumentTemplateResource;
use Filament\Resources\Pages\ListRecords;

final class ListDocumentTemplates extends ListRecords
{
    protected static string $resource = DocumentTemplateResource::class;
}

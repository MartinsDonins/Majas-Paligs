<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\BuildingSeriesResource\Pages;

use App\Filament\Platform\Resources\BuildingSeriesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListBuildingSeries extends ListRecords
{
    protected static string $resource = BuildingSeriesResource::class;

    /**
     * Filament 4/5 list pages have no default header actions: without this the create page was reachable only by
     * typing its URL (QA 2026-10-20).
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

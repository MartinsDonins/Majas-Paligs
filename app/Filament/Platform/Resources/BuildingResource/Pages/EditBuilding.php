<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\BuildingResource\Pages;

use App\Filament\Platform\Resources\BuildingResource;
use Filament\Resources\Pages\EditRecord;

final class EditBuilding extends EditRecord
{
    protected static string $resource = BuildingResource::class;

    /**
     * EditRecord fills the form from attributesToArray(), which includes `settings` cast to the BuildingSettings
     * value object — Livewire cannot dehydrate it, so every edit page was a 500 (QA 2026-10-20). Settings are edited
     * in the house's own panel and through the DPA action, never in this form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['settings']);

        return $data;
    }
}

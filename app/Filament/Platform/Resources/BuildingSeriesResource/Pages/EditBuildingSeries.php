<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\BuildingSeriesResource\Pages;

use App\Filament\Platform\Resources\BuildingSeriesResource;
use App\Models\BuildingSeries;
use Filament\Resources\Pages\EditRecord;

final class EditBuildingSeries extends EditRecord
{
    protected static string $resource = BuildingSeriesResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var BuildingSeries $record */
        $record = $this->record;
        $data['name'] = $record->getTranslations('name');
        $data['notes'] = $record->getTranslations('notes');

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\BuildingResource\Pages;

use App\Filament\Platform\Pages\BuildingGeneratorPage;
use App\Filament\Platform\Resources\BuildingResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateBuilding extends CreateRecord
{
    protected static string $resource = BuildingResource::class;

    /**
     * A new house has no stairwells yet: the next step is always the generator.
     */
    protected function getRedirectUrl(): string
    {
        return BuildingGeneratorPage::getUrl(['building' => $this->getRecord()->getKey()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\BuildingResource\Pages;

use App\Filament\Platform\Resources\BuildingResource;
use Filament\Resources\Pages\EditRecord;

final class EditBuilding extends EditRecord
{
    protected static string $resource = BuildingResource::class;
}

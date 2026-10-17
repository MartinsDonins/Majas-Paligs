<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\BuildingResource\Pages;

use App\Filament\Platform\Resources\BuildingResource;
use Filament\Resources\Pages\ListRecords;

final class ListBuildings extends ListRecords
{
    protected static string $resource = BuildingResource::class;
}

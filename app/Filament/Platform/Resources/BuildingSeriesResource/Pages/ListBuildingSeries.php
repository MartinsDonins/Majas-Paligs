<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\BuildingSeriesResource\Pages;

use App\Filament\Platform\Resources\BuildingSeriesResource;
use Filament\Resources\Pages\ListRecords;

final class ListBuildingSeries extends ListRecords
{
    protected static string $resource = BuildingSeriesResource::class;
}

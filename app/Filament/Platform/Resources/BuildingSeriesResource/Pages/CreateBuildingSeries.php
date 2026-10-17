<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\BuildingSeriesResource\Pages;

use App\Filament\Platform\Resources\BuildingSeriesResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateBuildingSeries extends CreateRecord
{
    protected static string $resource = BuildingSeriesResource::class;
}

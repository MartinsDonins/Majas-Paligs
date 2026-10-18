<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\VehicleResource\Pages;

use App\Filament\Manage\Resources\VehicleResource;
use Filament\Resources\Pages\ListRecords;

final class ListVehicles extends ListRecords
{
    protected static string $resource = VehicleResource::class;
}

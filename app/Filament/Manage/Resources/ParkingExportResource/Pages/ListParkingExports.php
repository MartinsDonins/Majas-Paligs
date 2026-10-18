<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\ParkingExportResource\Pages;

use App\Filament\Manage\Resources\ParkingExportResource;
use Filament\Resources\Pages\ListRecords;

final class ListParkingExports extends ListRecords
{
    protected static string $resource = ParkingExportResource::class;
}

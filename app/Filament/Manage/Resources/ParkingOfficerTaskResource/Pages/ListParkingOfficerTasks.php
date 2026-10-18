<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\ParkingOfficerTaskResource\Pages;

use App\Filament\Manage\Resources\ParkingOfficerTaskResource;
use Filament\Resources\Pages\ListRecords;

final class ListParkingOfficerTasks extends ListRecords
{
    protected static string $resource = ParkingOfficerTaskResource::class;
}

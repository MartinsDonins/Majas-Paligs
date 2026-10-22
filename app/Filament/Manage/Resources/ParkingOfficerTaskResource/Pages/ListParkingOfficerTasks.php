<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\ParkingOfficerTaskResource\Pages;

use App\Filament\Manage\Resources\Concerns\RedirectsBelowParkingL1;
use App\Filament\Manage\Resources\ParkingOfficerTaskResource;
use Filament\Resources\Pages\ListRecords;

final class ListParkingOfficerTasks extends ListRecords
{
    use RedirectsBelowParkingL1;

    protected static string $resource = ParkingOfficerTaskResource::class;
}

<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\ParkingLotResource\Pages;

use App\Filament\Manage\Resources\ParkingLotResource;
use Filament\Resources\Pages\ListRecords;

final class ListParkingLots extends ListRecords
{
    protected static string $resource = ParkingLotResource::class;
}

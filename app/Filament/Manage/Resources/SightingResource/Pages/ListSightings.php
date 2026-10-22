<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\SightingResource\Pages;

use App\Filament\Manage\Resources\Concerns\RedirectsBelowParkingL1;
use App\Filament\Manage\Resources\SightingResource;
use Filament\Resources\Pages\ListRecords;

final class ListSightings extends ListRecords
{
    use RedirectsBelowParkingL1;

    protected static string $resource = SightingResource::class;
}

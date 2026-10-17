<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\ApartmentResource\Pages;

use App\Filament\Manage\Resources\ApartmentResource;
use Filament\Resources\Pages\ListRecords;

final class ListApartments extends ListRecords
{
    protected static string $resource = ApartmentResource::class;
}

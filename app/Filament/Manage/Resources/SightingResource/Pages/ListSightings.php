<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\SightingResource\Pages;

use App\Filament\Manage\Resources\SightingResource;
use Filament\Resources\Pages\ListRecords;

final class ListSightings extends ListRecords
{
    protected static string $resource = SightingResource::class;
}

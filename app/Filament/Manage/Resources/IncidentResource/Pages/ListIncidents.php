<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\IncidentResource\Pages;

use App\Filament\Manage\Resources\IncidentResource;
use Filament\Resources\Pages\ListRecords;

final class ListIncidents extends ListRecords
{
    protected static string $resource = IncidentResource::class;
}

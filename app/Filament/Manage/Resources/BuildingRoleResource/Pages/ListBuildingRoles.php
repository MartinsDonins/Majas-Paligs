<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\BuildingRoleResource\Pages;

use App\Filament\Manage\Resources\BuildingRoleResource;
use Filament\Resources\Pages\ListRecords;

final class ListBuildingRoles extends ListRecords
{
    protected static string $resource = BuildingRoleResource::class;
}

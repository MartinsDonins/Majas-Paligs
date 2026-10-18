<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\PermitResource\Pages;

use App\Filament\Manage\Resources\PermitResource;
use Filament\Resources\Pages\ListRecords;

final class ListPermits extends ListRecords
{
    protected static string $resource = PermitResource::class;
}

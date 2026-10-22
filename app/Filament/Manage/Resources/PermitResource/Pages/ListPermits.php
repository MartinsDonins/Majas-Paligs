<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\PermitResource\Pages;

use App\Filament\Manage\Resources\Concerns\RedirectsBelowParkingL1;
use App\Filament\Manage\Resources\PermitResource;
use Filament\Resources\Pages\ListRecords;

final class ListPermits extends ListRecords
{
    use RedirectsBelowParkingL1;

    protected static string $resource = PermitResource::class;
}

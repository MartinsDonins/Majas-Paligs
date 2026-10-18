<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\ManagerReportResource\Pages;

use App\Filament\Manage\Resources\ManagerReportResource;
use Filament\Resources\Pages\ListRecords;

final class ListManagerReports extends ListRecords
{
    protected static string $resource = ManagerReportResource::class;
}

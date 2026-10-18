<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\HeatingReportResource\Pages;

use App\Filament\Manage\Resources\HeatingReportResource;
use Filament\Resources\Pages\ListRecords;

final class ListHeatingReports extends ListRecords
{
    protected static string $resource = HeatingReportResource::class;
}

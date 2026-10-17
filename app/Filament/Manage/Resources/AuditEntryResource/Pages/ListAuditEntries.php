<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\AuditEntryResource\Pages;

use App\Filament\Manage\Resources\AuditEntryResource;
use Filament\Resources\Pages\ListRecords;

final class ListAuditEntries extends ListRecords
{
    protected static string $resource = AuditEntryResource::class;
}

<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\SectionResource\Pages;

use App\Filament\Manage\Resources\SectionResource;
use Filament\Resources\Pages\ListRecords;

final class ListSections extends ListRecords
{
    protected static string $resource = SectionResource::class;
}

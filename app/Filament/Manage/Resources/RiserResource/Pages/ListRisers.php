<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\RiserResource\Pages;

use App\Filament\Manage\Resources\RiserResource;
use Filament\Resources\Pages\ListRecords;

final class ListRisers extends ListRecords
{
    protected static string $resource = RiserResource::class;
}

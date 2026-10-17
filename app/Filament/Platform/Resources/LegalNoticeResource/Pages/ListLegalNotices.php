<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\LegalNoticeResource\Pages;

use App\Filament\Platform\Resources\LegalNoticeResource;
use Filament\Resources\Pages\ListRecords;

final class ListLegalNotices extends ListRecords
{
    protected static string $resource = LegalNoticeResource::class;
}

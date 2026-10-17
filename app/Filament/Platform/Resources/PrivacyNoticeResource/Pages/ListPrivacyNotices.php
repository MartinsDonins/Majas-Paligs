<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\PrivacyNoticeResource\Pages;

use App\Filament\Platform\Resources\PrivacyNoticeResource;
use Filament\Resources\Pages\ListRecords;

final class ListPrivacyNotices extends ListRecords
{
    protected static string $resource = PrivacyNoticeResource::class;
}

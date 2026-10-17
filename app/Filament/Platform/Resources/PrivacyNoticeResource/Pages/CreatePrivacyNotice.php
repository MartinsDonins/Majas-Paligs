<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\PrivacyNoticeResource\Pages;

use App\Filament\Platform\Resources\PrivacyNoticeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreatePrivacyNotice extends CreateRecord
{
    protected static string $resource = PrivacyNoticeResource::class;
}

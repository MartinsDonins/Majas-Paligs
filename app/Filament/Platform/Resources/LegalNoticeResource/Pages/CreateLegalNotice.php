<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\LegalNoticeResource\Pages;

use App\Filament\Platform\Resources\LegalNoticeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateLegalNotice extends CreateRecord
{
    protected static string $resource = LegalNoticeResource::class;
}

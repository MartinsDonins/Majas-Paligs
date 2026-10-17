<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\LegalNoticeResource\Pages;

use App\Filament\Platform\Resources\LegalNoticeResource;
use App\Models\LegalNotice;
use Filament\Resources\Pages\EditRecord;

final class EditLegalNotice extends EditRecord
{
    protected static string $resource = LegalNoticeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var LegalNotice $record */
        $record = $this->record;
        $data['body'] = $record->getTranslations('body');

        return $data;
    }
}

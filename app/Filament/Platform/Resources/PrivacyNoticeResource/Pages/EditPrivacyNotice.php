<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\PrivacyNoticeResource\Pages;

use App\Filament\Platform\Resources\PrivacyNoticeResource;
use App\Models\PrivacyNoticeVersion;
use Filament\Resources\Pages\EditRecord;

final class EditPrivacyNotice extends EditRecord
{
    protected static string $resource = PrivacyNoticeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PrivacyNoticeVersion $record */
        $record = $this->record;
        abort_if($record->published_at !== null, 403);
        $data['body'] = $record->getTranslations('body');

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\ApartmentResource\Pages;

use App\Audit\AuditLogger;
use App\Filament\Manage\Resources\ApartmentResource;
use Filament\Resources\Pages\EditRecord;

final class EditApartment extends EditRecord
{
    protected static string $resource = ApartmentResource::class;

    protected function afterSave(): void
    {
        AuditLogger::record('apartment.update', $this->record, data: ['fields' => array_keys($this->record->getChanges())]);
    }
}

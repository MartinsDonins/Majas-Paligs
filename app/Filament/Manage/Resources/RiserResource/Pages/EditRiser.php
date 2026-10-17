<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\RiserResource\Pages;

use App\Audit\AuditLogger;
use App\Filament\Manage\Resources\RiserResource;
use Filament\Resources\Pages\EditRecord;

final class EditRiser extends EditRecord
{
    protected static string $resource = RiserResource::class;

    protected function afterSave(): void
    {
        AuditLogger::record('riser.update', $this->record, data: ['confidence' => $this->record->getAttribute('confidence')?->value]);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\SurveyRoundResource\Pages;

use App\Filament\Manage\Resources\SurveyRoundResource;
use Filament\Resources\Pages\ListRecords;

final class ListSurveyRounds extends ListRecords
{
    protected static string $resource = SurveyRoundResource::class;
}

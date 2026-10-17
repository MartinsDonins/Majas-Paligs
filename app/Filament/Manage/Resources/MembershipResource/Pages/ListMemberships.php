<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\MembershipResource\Pages;

use App\Filament\Manage\Resources\MembershipResource;
use Filament\Resources\Pages\ListRecords;

final class ListMemberships extends ListRecords
{
    protected static string $resource = MembershipResource::class;
}

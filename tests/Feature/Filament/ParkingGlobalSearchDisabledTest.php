<?php

declare(strict_types=1);

use App\Filament\Manage\Resources\ParkingExportResource;
use App\Filament\Manage\Resources\ParkingLotResource;
use App\Filament\Manage\Resources\ParkingOfficerTaskResource;
use App\Filament\Manage\Resources\PermitResource;
use App\Filament\Manage\Resources\SightingResource;
use App\Filament\Manage\Resources\VehicleResource;
use Filament\Facades\Filament;

/*
 * MVP-SPEC C.8 Feature/Filament/ParkingGlobalSearchDisabledTest: global search is off on the panel and on every
 * parking resource (plates must never be searchable or appear in search results).
 */
it('keeps global search off for the manage panel and all parking resources', function (string $resource): void {
    expect(Filament::getPanel('manage')->getGlobalSearchProvider())->toBeNull()
        ->and((new ReflectionProperty($resource, 'isGloballySearchable'))->getValue())->toBeFalse()
        ->and($resource::canGloballySearch())->toBeFalse();
})->with([
    VehicleResource::class, SightingResource::class, PermitResource::class, ParkingOfficerTaskResource::class,
    ParkingExportResource::class, ParkingLotResource::class,
]);

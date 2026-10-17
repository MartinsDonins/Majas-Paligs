<?php

declare(strict_types=1);

use App\Models\Apartment;
use App\Models\ApartmentCell;
use App\Models\ApartmentInvite;
use App\Models\ApartmentMembership;
use App\Models\BuildingExport;
use App\Models\BuildingPrivacyNotice;
use App\Models\BuildingRole;
use App\Models\LandParcel;
use App\Models\Riser;
use App\Models\RiserLink;
use App\Models\Room;
use App\Models\Section;
use App\Tenancy\BelongsToBuilding;
use App\Tenancy\Exceptions\MissingBuildingContext;

/**
 * Every tenant model (uses BelongsToBuilding).
 *
 * @return list<class-string>
 */
function tenantModels(): array
{
    return [
        Section::class, LandParcel::class, Apartment::class, ApartmentCell::class, Room::class, Riser::class,
        RiserLink::class, BuildingPrivacyNotice::class, ApartmentInvite::class, ApartmentMembership::class,
        BuildingRole::class, BuildingExport::class,
    ];
}

it('throws for every tenant model query without a current building', function (string $model): void {
    expect(in_array(BelongsToBuilding::class, class_uses_recursive($model), true))->toBeTrue();

    $model::query()->count();
})->with(tenantModels())->throws(MissingBuildingContext::class);

it('refuses to create a tenant row without a context', function (): void {
    makeBuilding();
    (new LandParcel(['cadastre_no' => 'X']))->save();
})->throws(MissingBuildingContext::class);

it('scopes queries to the current building only', function (): void {
    $a = makeBuilding('scope-a', 1, 2);
    $b = makeBuilding('scope-b', 1, 3);

    expect(inBuilding($a, fn () => Apartment::query()->count()))->toBe(8)
        ->and(inBuilding($b, fn () => Apartment::query()->count()))->toBe(12)
        ->and(inBuilding($a, fn () => Apartment::query()->whereKey(apartment($b, 1)->id)->exists()))->toBeFalse();
});

it('refuses to write a row of another building inside a context', function (): void {
    $a = makeBuilding('scope-c', 1, 1);
    $b = makeBuilding('scope-d', 1, 1);
    $foreign = apartment($b, 1);

    inBuilding($a, function () use ($foreign): void {
        $foreign->forceFill(['override_note' => 'x'])->save();
    });
})->throws(MissingBuildingContext::class);

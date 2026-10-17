<?php

declare(strict_types=1);

use App\Models\Building;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\BuildingContextAlreadySet;
use App\Tenancy\Exceptions\MissingBuildingContext;

function bareBuilding(string $slug): Building
{
    return Building::query()->create(['slug' => $slug, 'name' => $slug, 'address_line' => $slug, 'city' => 'Rīga', 'floors' => 9]);
}

it('throws when no building is set', function (): void {
    expect(CurrentBuilding::has())->toBeFalse();
    CurrentBuilding::get();
})->throws(MissingBuildingContext::class);

it('treats set() to the same building as a no-op', function (): void {
    $a = bareBuilding('a-house');
    CurrentBuilding::set($a);
    CurrentBuilding::set($a);

    expect(CurrentBuilding::id())->toBe($a->id);
});

it('refuses to re-point set() to a different building', function (): void {
    $a = bareBuilding('a-house');
    $b = bareBuilding('b-house');
    CurrentBuilding::set($a);
    CurrentBuilding::set($b);
})->throws(BuildingContextAlreadySet::class);

it('restores the previous context after runFor, also on exception', function (): void {
    $a = bareBuilding('a-house');
    $b = bareBuilding('b-house');

    CurrentBuilding::set($a);
    $seen = CurrentBuilding::runFor($b, fn (Building $x) => CurrentBuilding::id());
    expect($seen)->toBe($b->id)->and(CurrentBuilding::id())->toBe($a->id);

    try {
        CurrentBuilding::runFor($b, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(CurrentBuilding::id())->toBe($a->id);
});

it('restores "no context" after runFor', function (): void {
    $a = bareBuilding('a-house');
    CurrentBuilding::runFor($a, fn () => null);

    expect(CurrentBuilding::has())->toBeFalse();
});

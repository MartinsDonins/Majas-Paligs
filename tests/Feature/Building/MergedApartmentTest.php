<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Livewire\Onboarding\RedeemInvite;
use App\Models\Apartment;
use App\Models\ApartmentCell;
use App\Models\RiserLink;
use App\Models\Room;
use App\Services\Buildings\ApartmentMerger;
use App\Services\Buildings\BuildingGrid;
use Livewire\Livewire;

it('merges two cells into one apartment keeping both riser links and retiring the absorbed number', function (): void {
    $b = makeBuilding('merge-a', 1, 2, open: true);
    $absorbedCode = makeInvite($b, 2);

    inBuilding($b, function (): void {
        $primary = Apartment::query()->where('number', 1)->firstOrFail();
        $absorbed = Apartment::query()->where('number', 2)->firstOrFail();
        $linksBefore = RiserLink::query()->whereIn('apartment_id', [$primary->id, $absorbed->id])->count();
        $roomsBefore = Room::query()->whereIn('apartment_id', [$primary->id, $absorbed->id])->count();

        app(ApartmentMerger::class)->merge($primary, $absorbed, 'Apvienoti 2004');
        $primary->refresh();

        expect($primary->retired_numbers)->toBe([2])
            ->and($primary->is_override)->toBeTrue()
            ->and(Apartment::query()->where('number', 2)->exists())->toBeFalse()
            ->and(ApartmentCell::query()->where('apartment_id', $primary->id)->count())->toBe(2)
            ->and(Room::query()->where('apartment_id', $primary->id)->count())->toBe($roomsBefore)
            ->and(RiserLink::query()->where('apartment_id', $primary->id)->count())->toBeGreaterThanOrEqual($linksBefore - 4)
            ->and(RiserLink::query()->where('apartment_id', $primary->id)->whereNotNull('room_id')->count())
            ->toBe(RiserLink::query()->whereNotNull('room_id')->whereIn('room_id', Room::query()->where('apartment_id', $primary->id)->select('id'))->count());

        // the grid renders both cells with the primary number and a merge marker
        $grid = app(BuildingGrid::class)->forCurrentBuilding();
        $floor1 = $grid['sections'][0]['rows'][1];
        expect($floor1[1]['number'])->toBe(1)->and($floor1[2]['number'])->toBe(1)->and($floor1[2]['primary'])->toBeFalse();
    });

    // the absorbed number no longer redeems
    Livewire::test(RedeemInvite::class)->set('code', $absorbedCode)->call('submit')->assertSet('error', __('onboarding.code.invalid'));
});

it('archives (not deletes) an absorbed apartment that has history', function (): void {
    $b = makeBuilding('merge-b', 1, 1, open: true);
    member(makeUser(), $b, 4, MembershipStatus::Ended);

    inBuilding($b, function (): void {
        $primary = Apartment::query()->where('number', 3)->firstOrFail();
        $absorbed = Apartment::query()->where('number', 4)->firstOrFail();
        app(ApartmentMerger::class)->merge($primary, $absorbed);

        expect($absorbed->refresh()->archived_at)->not->toBeNull()
            ->and(Apartment::query()->active()->pluck('number')->all())->not->toContain(4);
    });
});

it('refuses to merge across stairwells', function (): void {
    $b = makeBuilding('merge-c', 2, 1);

    inBuilding($b, fn () => app(ApartmentMerger::class)->merge(
        Apartment::query()->where('number', 1)->firstOrFail(),
        Apartment::query()->where('number', 5)->firstOrFail(),
    ));
})->throws(InvalidArgumentException::class);

<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\IncidentKind;
use App\Enums\IncidentOrigin;
use App\Enums\RiserKind;
use App\Models\Incident;
use App\Models\Riser;
use App\Models\RiserLink;
use App\Services\Buildings\ApartmentMerger;
use App\Services\Outages\ApartmentFootprint;
use App\Services\Outages\IncidentService;
use App\Services\Outages\OutageScope;
use App\Services\Outages\OutageScopeResolver;

/*
 * MVP-SPEC B.2a — residents never pick a riser: 0 / 1 / 2+ risers of a kind, "Visur", unknown links → column,
 * covering notice wins, merged apartment.
 */
beforeEach(function (): void {
    $this->b = heatingHouse('scope-house', 1, 3);
    $this->resolver = app(OutageScopeResolver::class);
});

it('maps exactly one riser of the kind to riser:{id}', function (): void {
    $scope = inBuilding($this->b, fn () => $this->resolver->resolve(apartment($this->b, 1), IncidentKind::WaterCold));

    expect($scope->needsChoice())->toBeFalse()
        ->and($scope->targets)->toHaveCount(1)
        ->and($scope->targets[0]->key)->toBe('riser:'.riserId($this->b, 'CW1-K'));
});

it('asks one room question when several risers of the kind serve the apartment, and "Visur" maps to all', function (): void {
    $scope = inBuilding($this->b, fn () => $this->resolver->resolve(apartment($this->b, 1), IncidentKind::HeatingNone));

    expect($scope->needsChoice())->toBeTrue()
        ->and(count($scope->options))->toBe(3) // 2-room apartment: room1, room2, kitchen radiators
        ->and(collect($scope->options)->pluck('label')->all())->toContain('Virtuve');

    $kitchen = collect($scope->options)->firstWhere('label', 'Virtuve');
    expect($scope->choose($kitchen['key']))->toHaveCount(1)
        ->and($scope->choose($kitchen['key'])[0]->key)->toBe('riser:'.riserId($this->b, 'H1-1-KITCHEN'))
        ->and($scope->choose(OutageScope::ALL))->toHaveCount(3);
});

it('falls back to the column when the apartment has no links of that kind', function (): void {
    $apt = apartment($this->b, 6); // floor 2, position 2
    inBuilding($this->b, fn () => RiserLink::query()->where('apartment_id', $apt->id)
        ->whereIn('riser_id', Riser::query()->where('kind', RiserKind::HotWater->value)->select('id'))->delete());

    $scope = inBuilding($this->b, fn () => $this->resolver->resolve($apt, IncidentKind::WaterHot));

    expect($scope->needsChoice())->toBeFalse()
        ->and($scope->targets[0]->key)->toBe('col:'.section($this->b, 1)->id.':2');
});

it('joins a covering notice before creating a resident incident', function (): void {
    $admin = makeUser();
    grantRole($admin, $this->b, BuildingRoleType::BuildingAdmin);
    $resident = makeUser();
    member($resident, $this->b, 1);

    $notice = inBuilding($this->b, fn () => app(IncidentService::class)->postNotice($admin, IncidentKind::WaterCold, [
        'target' => 'section', 'section_id' => section($this->b, 1)->id, 'reason_lv' => 'Remonts', 'reason_ru' => 'Ремонт',
    ]));

    $result = inBuilding($this->b, fn () => app(IncidentService::class)->report($resident, apartment($this->b, 1), IncidentKind::WaterCold));

    expect($result->incidents->pluck('id')->all())->toBe([$notice->id])
        ->and($result->joinedNotice())->toBeTrue()
        ->and(inBuilding($this->b, fn () => Incident::query()->where('origin', IncidentOrigin::Resident->value)->count()))->toBe(0);
});

it('treats a merged apartment as covering all its cells', function (): void {
    $a1 = apartment($this->b, 1);
    $a2 = apartment($this->b, 2);
    inBuilding($this->b, fn () => app(ApartmentMerger::class)->merge($a1, $a2, 'test merge'));

    $footprint = inBuilding($this->b, fn () => ApartmentFootprint::of($a1->refresh()));
    $column2 = new Incident(['scope_key' => 'col:'.section($this->b, 1)->id.':2']);

    expect($footprint->positions)->toBe([1, 2])
        ->and($footprint->coveredBy($column2))->toBeTrue();

    $scope = inBuilding($this->b, fn () => $this->resolver->resolve($a1->refresh(), IncidentKind::WaterCold));
    expect($scope->targets)->toHaveCount(1); // both cells on the left side → one cold-water riser
});

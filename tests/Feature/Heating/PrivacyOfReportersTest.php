<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\ReportSource;
use App\Livewire\Elder\ProxyReport;
use App\Livewire\Heating\Grid;
use App\Models\HeatingReport;
use App\Services\Heating\DigestBuilder;
use App\Services\Heating\HeatingGridQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/*
 * MVP-SPEC B.7 AC 3 / PLANNING §4.6 "riser + floor = apartment": resident payloads (HTML, Livewire snapshot,
 * digest) contain no apartment ids of others, no floors_reported, no per-floor segment markup, no top_floor_only.
 * Staff views only for building_admin and the elder of THAT stairwell.
 */
beforeEach(function (): void {
    $this->b = heatingHouse('privacy-house', 2, 9);
    // Stairwell 2 = apartments 37..72. Cold reports on the top floors only (riser H2-1-KITCHEN: 37, 41, … 69).
    $this->reporters = [residentReports($this->b, 65, 'cold'), residentReports($this->b, 69, 'cold')];
    $this->resident = makeUser();
    member($this->resident, $this->b, 37);

    $this->elder1 = makeUser();
    member($this->elder1, $this->b, 1);
    grantRole($this->elder1, $this->b, BuildingRoleType::SectionElder, 1);

    $this->elder2 = makeUser();
    member($this->elder2, $this->b, 38);
    grantRole($this->elder2, $this->b, BuildingRoleType::SectionElder, 2);

    $this->volunteer = makeUser();
    member($this->volunteer, $this->b, 2);
    grantRole($this->volunteer, $this->b, BuildingRoleType::Volunteer, 2);

    $this->admin = makeUser();
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
});

/**
 * Restricted markers that must never reach a resident payload.
 *
 * @return list<string>
 */
function restrictedMarkers(Collection $apartmentIds): array
{
    return [
        'floors_reported', 'top_floor_only', 'class="floorbar"', __('heating.staff.top_floor_only'), __('heating.staff.floors'),
        'whole_riser_under', __('heating.staff.badge'),
        ...$apartmentIds->map(fn ($id) => '"apartment_id":'.$id)->all(),
    ];
}

it('keeps resident HTML and the Livewire snapshot free of per-floor / per-apartment data', function (string $who): void {
    $user = match ($who) {
        'resident' => $this->resident,
        'elder of stairwell 1' => $this->elder1,
        'volunteer of stairwell 2' => $this->volunteer,
    };

    $html = $this->actingAs($user)->get('/b/privacy-house/heating?k=2')->assertOk()->getContent();
    $ids = inBuilding($this->b, fn () => HeatingReport::query()->pluck('apartment_id')->unique()->values());

    foreach (restrictedMarkers($ids) as $marker) {
        expect(str_contains($html, $marker))->toBeFalse("resident payload for {$who} contains [{$marker}]");
    }
    // The column shows the aggregate (unconfirmed → confirmed after 2 apartments), never the reporters' floors.
    expect($html)->toContain('heat-cold')->toContain(__('heating.tip.air'));

    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
    foreach ($m[1] as $snapshot) {
        $json = htmlspecialchars_decode($snapshot);
        foreach (['floors_reported', 'top_floor_only', 'apartment_id'] as $marker) {
            expect(str_contains($json, $marker))->toBeFalse("snapshot contains {$marker}");
        }
    }
})->with(['resident', 'elder of stairwell 1', 'volunteer of stairwell 2']);

it('gives the staff view to building_admin and to the elder of THAT stairwell only', function (): void {
    $html = $this->actingAs($this->elder2)->get('/b/privacy-house/heating?k=2')->assertOk()->getContent();
    expect($html)->toContain(__('heating.staff.badge'))->toContain('class="floorbar"')->toContain(__('heating.staff.top_floor_only'));

    $admin = $this->actingAs($this->admin)->get('/b/privacy-house/heating?k=2')->assertOk()->getContent();
    expect($admin)->toContain(__('heating.staff.badge'));

    // Elder 2 in stairwell 1 → resident view.
    $other = $this->actingAs($this->elder2)->get('/b/privacy-house/heating?k=1')->assertOk()->getContent();
    expect($other)->not->toContain(__('heating.staff.badge'))->not->toContain('class="floorbar"');
});

it('refuses per-apartment read models and proxy reports outside the elder’s stairwell (403)', function (): void {
    $section2 = section($this->b, 2);

    expect(fn () => inBuilding($this->b, fn () => app(HeatingGridQuery::class)->staffSection($this->elder1, $section2)))->toThrow(AuthorizationException::class)
        ->and(fn () => inBuilding($this->b, fn () => app(HeatingGridQuery::class)->staffSection($this->volunteer, $section2)))->toThrow(AuthorizationException::class);

    expect(fn () => reportHeating($this->elder1, $this->b, 45, ['kitchen' => 'cold'], ReportSource::Phone))->toThrow(AuthorizationException::class);

    // Through the Livewire component: apartment 45 is not offered and a forged number does nothing.
    inBuilding($this->b, fn () => Livewire::actingAs($this->elder1)->test(ProxyReport::class)
        ->set('apartmentNumber', 45)->set('states.kitchen', 'cold')->call('save')->assertHasErrors('apartmentNumber'));
});

it('keeps the WhatsApp digest aggregate-only', function (): void {
    $text = inBuilding($this->b, fn () => app(DigestBuilder::class)->forSection($this->b, section($this->b, 2)));

    expect($text)->toContain('2. k.t.')->toContain('—')
        ->not->toContain('65')->not->toContain('69')
        ->and(DigestBuilder::shareUrl($text))->toStartWith('https://wa.me/?text=');
});

it('never exposes the restricted fields through the resident read model type', function (): void {
    $view = residentSectionView($this->b, 2);
    $props = array_map(fn ($p) => $p->getName(), (new ReflectionClass($view->risers[0]))->getProperties());

    expect($props)->not->toContain('floorsReported')->not->toContain('topFloorOnly')->not->toContain('apartmentIds')
        ->and($view->staffRisers)->toBe([])->and($view->apartments)->toBe([]);
});

it('shows the Livewire grid component to a resident without staff properties', function (): void {
    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(Grid::class, ['mode' => 'heating'])
        ->set('stairwell', 2)
        ->assertViewHas('staff', false)
        ->assertDontSee(__('heating.staff.badge')));
});

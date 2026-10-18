<?php

declare(strict_types=1);

use App\Enums\IncidentKind;
use App\Livewire\Outage\MeTooButton;
use App\Livewire\Outage\ReportSheet;
use App\Models\IncidentConfirmation;
use App\Services\Outages\IncidentService;
use App\Support\Copy;
use Livewire\Livewire;

/*
 * "Man arī" (B.2a rule 5, AC 6): one confirmation per apartment and incident — repeated taps and two members of the
 * same apartment count once; apartments outside the scope cannot confirm.
 */
beforeEach(function (): void {
    $this->b = heatingHouse('metoo-house', 1, 3);
    $this->first = makeUser();
    member($this->first, $this->b, 1);
    $this->incident = inBuilding($this->b, fn () => app(IncidentService::class)->report($this->first, apartment($this->b, 1), IncidentKind::WaterHot))->incidents->first();
});

it('is idempotent per apartment', function (): void {
    $neighbour = makeUser();
    member($neighbour, $this->b, 5);
    $flatmate = makeUser();
    member($flatmate, $this->b, 5);

    inBuilding($this->b, function (): void {
        foreach ([1, 2, 3] as $tap) {
            Livewire::actingAs($this->first)->test(MeTooButton::class, ['incidentId' => $this->incident->id])->call('confirm');
        }
    });
    inBuilding($this->b, fn () => Livewire::actingAs($neighbour)->test(MeTooButton::class, ['incidentId' => $this->incident->id])->call('confirm')->assertSee(__('outage.me_too.done')));
    inBuilding($this->b, fn () => Livewire::actingAs($flatmate)->test(MeTooButton::class, ['incidentId' => $this->incident->id])->call('confirm'));

    expect(inBuilding($this->b, fn () => IncidentConfirmation::query()->where('incident_id', $this->incident->id)->count()))->toBe(2);
});

it('refuses apartments outside the incident scope (403)', function (): void {
    $otherSide = makeUser();
    member($otherSide, $this->b, 3); // position 3 → right-side riser, not covered

    inBuilding($this->b, fn () => Livewire::actingAs($otherSide)->test(MeTooButton::class, ['incidentId' => $this->incident->id])
        ->assertDontSee(__('outage.me_too.button'))
        ->call('confirm')
        ->assertForbidden());
});

it('reports via the "Vai ir ūdens?" sheet and shows the shared count', function (): void {
    $neighbour = makeUser();
    member($neighbour, $this->b, 9);

    inBuilding($this->b, fn () => Livewire::actingAs($neighbour)->test(ReportSheet::class)
        ->call('choose', 'water_hot')
        ->call('setSince', 'hour')
        ->call('submit')
        ->assertSet('apartments', 2)
        ->assertSee(Copy::count('outage.report.joined', 2, ['count' => 2])));
});

it('asks the room question only when several risers of the kind serve the apartment', function (): void {
    $u = makeUser();
    member($u, $this->b, 2);

    inBuilding($this->b, fn () => Livewire::actingAs($u)->test(ReportSheet::class)
        ->call('choose', 'water_cold')->assertViewHas('options', [])
        ->call('choose', 'heating_none')->assertViewHas('options', fn ($o) => count($o) === 3)
        ->call('submit')->assertHasErrors('answer')
        ->set('answer', 'all')->call('submit')->assertHasNoErrors()->assertSet('apartments', 1));
});

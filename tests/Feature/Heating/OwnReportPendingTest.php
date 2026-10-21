<?php

declare(strict_types=1);

use App\Jobs\RecomputeRiserStatus;
use App\Livewire\Heating\Grid;
use App\Livewire\Heating\ReportSheet;
use App\Services\Heating\RiserStatusProjector;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * FIX-007.1 (Karen, round 1): the grid reads the projections, which RecomputeRiserStatus refreshes ~10 s after a
 * report (debounced). Meanwhile the reporter saw "Esiet pirmais" for their own stairwell. Now the reporter's OWN
 * report shows at once (own cell + note) and the page polls until the projection has caught up.
 */
beforeEach(function (): void {
    Queue::fake([RecomputeRiserStatus::class]); // the debounced job has not run yet — exactly the production gap
    $this->b = heatingHouse('own-heat', 1, 3);
    $this->reporter = makeUser();
    member($this->reporter, $this->b, 1);
});

it('shows the reporter their own report right away instead of "be the first", until the projection caught up', function (): void {
    $this->actingAs($this->reporter)->get('/b/own-heat/heating')->assertOk()
        ->assertSee(__('heating.empty.section'))
        ->assertDontSee(__('heating.own.pending', ['state' => __('heating.state.cold')]));

    reportHeating($this->reporter, $this->b, 1, ['kitchen' => 'cold', 'room1' => 'warm']);
    Queue::assertPushed(RecomputeRiserStatus::class);

    $this->actingAs($this->reporter)->get('/b/own-heat/heating')->assertOk()
        ->assertDontSee(__('heating.empty.section'))
        ->assertSee(__('heating.own.pending', ['state' => __('heating.state.cold')]))
        ->assertSee(__('heating.own.cell', ['state' => __('heating.state.cold')]))
        ->assertSee('wire:poll.3s', false);

    // The debounced recompute runs: the normal (aggregate) grid takes over and the page stops polling fast.
    $this->travel(2)->seconds();
    inBuilding($this->b, fn () => app(RiserStatusProjector::class)->recompute());

    $this->actingAs($this->reporter)->get('/b/own-heat/heating')->assertOk()
        ->assertDontSee(__('heating.empty.section'))
        ->assertDontSee(__('heating.own.pending', ['state' => __('heating.state.cold')]))
        ->assertDontSee('wire:poll.3s', false);
});

it('never shows one resident\'s fresh report to a neighbour (own rows only)', function (): void {
    $neighbour = makeUser();
    member($neighbour, $this->b, 2);

    reportHeating($this->reporter, $this->b, 1, ['kitchen' => 'cold']);

    $this->actingAs($neighbour)->get('/b/own-heat/heating')->assertOk()
        ->assertSee(__('heating.empty.section'))
        ->assertDontSee(__('heating.own.pending', ['state' => __('heating.state.cold')]))
        ->assertDontSee(__('heating.own.cell', ['state' => __('heating.state.cold')]));
});

it('also replaces the empty list view with the own-report note', function (): void {
    reportHeating($this->reporter, $this->b, 1, ['kitchen' => 'lukewarm']);

    $this->actingAs($this->reporter)->get('/b/own-heat/heating?v=list')->assertOk()
        ->assertSee(__('heating.own.pending', ['state' => __('heating.state.lukewarm')]))
        ->assertDontSee(__('heating.empty.section'));
});

it('re-renders the grid after saving and stops reopening the report sheet (?report=1)', function (): void {
    inBuilding($this->b, function (): void {
        Livewire::actingAs($this->reporter)->test(ReportSheet::class)
            ->call('allSame', 'cold')->call('save')
            ->assertDispatched('heating-reported');

        Livewire::withQueryParams(['report' => '1'])->actingAs($this->reporter)
            ->test(Grid::class, ['mode' => 'heating'])
            ->assertSet('openReport', true)
            ->dispatch('heating-reported')
            ->assertSet('openReport', false)
            ->assertSee(__('heating.own.pending', ['state' => __('heating.state.cold')]));
    });
});

it('drops the own-report note again after an undo', function (): void {
    inBuilding($this->b, function (): void {
        Livewire::actingAs($this->reporter)->test(ReportSheet::class)
            ->call('allSame', 'warm')->call('save')
            ->call('undo')
            ->assertSet('undone', true)
            ->assertDispatched('heating-reported');

        Livewire::actingAs($this->reporter)->test(Grid::class, ['mode' => 'heating'])
            ->assertDontSee(__('heating.own.pending', ['state' => __('heating.state.warm')]))
            ->assertSee(__('heating.empty.section'));
    });
});

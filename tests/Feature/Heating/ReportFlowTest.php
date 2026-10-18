<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\DisputeStatus;
use App\Enums\MembershipStatus;
use App\Enums\RiserCheckResult;
use App\Livewire\Heating\Grid;
use App\Livewire\Heating\ReportSheet;
use App\Models\ColumnStatus;
use App\Models\HeatingReport;
use App\Models\Riser;
use App\Models\RiserDispute;
use App\Models\RiserStatus;
use App\Services\Heating\HeatingGridQuery;
use App\Services\Heating\RiserReviewService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * MVP-SPEC B.7 AC 1, 2, 4, 5, 9, 12 — report flow, confirmation rule, coverage, polling, offline behaviour.
 */
beforeEach(function (): void {
    $this->b = heatingHouse('flow-house', 1, 9);
    $this->resident = makeUser();
    member($this->resident, $this->b, 1); // floor 1, position 1
});

it('reports a cold kitchen in ≤4 taps and shows the riser count (AC 1)', function (): void {
    residentReports($this->b, 5, 'cold', ['kitchen' => 'cold']); // the neighbour above, same riser

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(ReportSheet::class)
        ->set('states.kitchen', 'cold')              // tap 2 (tap 1 = "Ziņot")
        ->call('save')                               // tap 3
        ->assertSet('confirmedCount', 2)
        ->assertSee(__('heating.report.thanks'))
        ->assertSee(trans_choice('heating.report.thanks_count', 2, ['count' => 2])));

    expect(inBuilding($this->b, fn () => HeatingReport::query()->where('apartment_id', apartment($this->b, 1)->id)->count()))->toBe(1);
});

it('fills every room with "Visās telpās vienādi"', function (): void {
    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(ReportSheet::class)
        ->call('allSame', 'warm')
        ->call('save')
        ->assertSet('submission', fn ($v) => is_string($v)));

    // 2-room apartment: room1, room2, kitchen, bath (towel dryer), wc
    expect(inBuilding($this->b, fn () => HeatingReport::query()->where('status', 'warm')->count()))->toBe(5);
});

it('shows one report as unconfirmed and two apartments as confirmed; same apartment twice never confirms (AC 2)', function (): void {
    $kitchen = riserId($this->b, 'H1-1-KITCHEN');

    reportHeating($this->resident, $this->b, 1, ['kitchen' => 'cold']);
    $first = inBuilding($this->b, fn () => RiserStatus::query()->where('riser_id', $kitchen)->where('round_key', 'rolling')->first());
    expect($first->getAttribute('confirmed'))->toBeFalse()->and((int) $first->getAttribute('distinct_apartments'))->toBe(1);

    reportHeating($this->resident, $this->b, 1, ['kitchen' => 'cold']); // same apartment again
    $again = inBuilding($this->b, fn () => RiserStatus::query()->where('riser_id', $kitchen)->where('round_key', 'rolling')->first());
    expect($again->getAttribute('confirmed'))->toBeFalse();

    residentReports($this->b, 9, 'cold', ['kitchen' => 'cold']); // floor 3, same column
    $second = inBuilding($this->b, fn () => RiserStatus::query()->where('riser_id', $kitchen)->where('round_key', 'rolling')->first());
    expect($second->getAttribute('confirmed'))->toBeTrue()
        ->and($second->getAttribute('status'))->toBe('cold')
        ->and((int) $second->getAttribute('distinct_apartments'))->toBe(2);

    $column = inBuilding($this->b, fn () => ColumnStatus::query()->where('position', 1)->where('round_key', 'rolling')->first());
    expect($column->getAttribute('worst_confirmed_status'))->toBe('cold');
});

it('supersedes the previous report of the same room and keeps exactly one active row', function (): void {
    reportHeating($this->resident, $this->b, 1, ['kitchen' => 'cold']);
    reportHeating($this->resident, $this->b, 1, ['kitchen' => 'warm']);

    $rows = inBuilding($this->b, fn () => HeatingReport::query()->where('apartment_id', apartment($this->b, 1)->id)->orderBy('id')->get());
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->superseded_at)->not->toBeNull()
        ->and($rows[1]->superseded_at)->toBeNull()
        ->and($rows[1]->status->value)->toBe('warm');
});

it('undoes a fresh submission and restores the superseded row', function (): void {
    reportHeating($this->resident, $this->b, 1, ['kitchen' => 'cold']);

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(ReportSheet::class)
        ->set('states.kitchen', 'warm')
        ->call('save')
        ->call('undo')
        ->assertSet('undone', true));

    $active = inBuilding($this->b, fn () => HeatingReport::query()->active()->get());
    expect($active)->toHaveCount(1)->and($active[0]->status->value)->toBe('cold');
});

it('validates temperature, triage and the private note length', function (): void {
    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(ReportSheet::class)
        ->set('states.kitchen', 'cold')->set('temperature', '55')->call('save')->assertHasErrors('temperature'));
    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(ReportSheet::class)
        ->set('states.kitchen', 'cold')->set('note', str_repeat('x', 281))->call('save')->assertHasErrors('note'));
    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(ReportSheet::class)
        ->set('states.kitchen', 'cold')->set('temperature', '18,5')->set('triage', 'air_suspected')->set('note', 'Aizvērts vārsts?')->call('save')->assertHasNoErrors());

    $r = inBuilding($this->b, fn () => HeatingReport::query()->active()->firstOrFail());
    expect($r->temperature_c)->toBe('18.5')->and($r->triage?->value)->toBe('air_suspected')->and($r->note_private)->toBe('Aizvērts vārsts?');
});

it('rejects a room that does not belong to my apartment', function (): void {
    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(ReportSheet::class)
        ->set('states.room3', 'cold') // 2-room apartment has no room3
        ->call('save')
        ->assertHasErrors('states'));
});

it('keeps pending members read-only (AC A.4)', function (): void {
    $pending = makeUser();
    member($pending, $this->b, 2, MembershipStatus::Pending);

    $this->actingAs($pending)->get('/b/flow-house/heating')->assertOk()->assertSee(__('heating.pending_read_only'));
    inBuilding($this->b, fn () => Livewire::actingAs($pending)->test(ReportSheet::class)->call('save')->assertForbidden());
});

it('renders "Nav datu" for columns without data and the correct coverage text (AC 4)', function (): void {
    residentReports($this->b, 1, 'warm', ['kitchen' => 'warm']);
    residentReports($this->b, 5, 'warm', ['kitchen' => 'warm']);

    $view = residentSectionView($this->b, 1);
    expect($view->column(1)->floorsCovered)->toBe(2)
        ->and($view->column(1)->displayState())->toBe('warm')
        ->and($view->column(2)->displayState())->toBe('none');

    $this->actingAs($this->resident)->get('/b/flow-house/heating')->assertOk()
        ->assertSee(__('heating.coverage.short', ['covered' => 2, 'total' => 9]))
        ->assertSee(__('heating.coverage.short', ['covered' => 0, 'total' => 9]))
        ->assertSee('heat-none', false);
});

it('freezes a disputed column as "Pārbaudāms" and shows a check as "Pārbaudīts" (AC 5)', function (): void {
    residentReports($this->b, 1, 'cold', ['kitchen' => 'cold']);
    residentReports($this->b, 5, 'cold', ['kitchen' => 'cold']);
    $kitchen = inBuilding($this->b, fn () => Riser::query()->findOrFail(riserId($this->b, 'H1-1-KITCHEN')));

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(Grid::class, ['mode' => 'heating'])->call('dispute', $kitchen->id)->assertSet('flash', 'disputed'));
    expect(residentSectionView($this->b, 1)->column(1)->displayState())->toBe('disputed');

    $admin = makeUser();
    grantRole($admin, $this->b, BuildingRoleType::BuildingAdmin);
    $dispute = inBuilding($this->b, fn () => RiserDispute::query()->firstOrFail());
    inBuilding($this->b, fn () => app(RiserReviewService::class)->decide($admin, $dispute, DisputeStatus::Rejected));
    inBuilding($this->b, fn () => app(RiserReviewService::class)->check($admin, $kitchen, RiserCheckResult::AirVented));

    $view = residentSectionView($this->b, 1);
    expect($view->column(1)->displayState())->toBe('cold')
        ->and($view->column(1)->checked)->toBeTrue()
        ->and(collect($view->risers)->firstWhere('riserId', $kitchen->id)->checkedState)->toBe('air_vented')
        ->and($kitchen->refresh()->confidence->value)->toBe('resident_confirmed');
});

it('refuses a dispute on a riser that is not linked to my apartment', function (): void {
    $other = riserId($this->b, 'H1-2-KITCHEN');

    inBuilding($this->b, fn () => Livewire::actingAs($this->resident)->test(Grid::class, ['mode' => 'heating'])->call('dispute', $other)->assertForbidden());
});

it('loads the grid data with at most 3 queries from the projections (AC 9)', function (): void {
    residentReports($this->b, 1, 'cold');
    $query = app(HeatingGridQuery::class);
    $section = section($this->b, 1);

    $count = inBuilding($this->b, function () use ($query, $section): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $query->residentSection($section);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    });

    expect($count)->toBeLessThanOrEqual(3);
});

it('polls only while visible and never queues saves offline (AC 9, 12)', function (): void {
    $html = $this->actingAs($this->resident)->get('/b/flow-house/heating?report=1')->assertOk()->getContent();

    expect($html)->toContain('wire:poll.visible.60s')
        ->toContain('data-requires-online')
        ->toContain(__('shell.offline'))
        ->toContain('data-open="1"')
        ->not->toContain('serviceWorker.ready');
});

it('renders the heating and water pages in LV and RU', function (string $locale): void {
    $this->resident->forceFill(['locale' => $locale])->save();
    residentReports($this->b, 5, 'cold');

    $this->actingAs($this->resident)->get('/b/flow-house/heating')->assertOk()->assertSee(__('heating.title', [], $locale))->assertSee(__('heating.legend.title', [], $locale));
    $this->actingAs($this->resident)->get('/b/flow-house/water')->assertOk()->assertSee(__('outage.report.cta', [], $locale));
    $this->actingAs($this->resident)->get('/b/flow-house')->assertOk()->assertSee(__('home.action.heating', [], $locale));
})->with(['lv', 'ru']);

it('answers 404 when the module is off', function (): void {
    $b = heatingHouse('flow-off', 1, 2, modules: ['heating' => false, 'outages' => false]);
    $u = makeUser();
    member($u, $b, 1);

    $this->actingAs($u)->get('/b/flow-off/heating')->assertNotFound();
    $this->actingAs($u)->get('/b/flow-off/water')->assertNotFound();
});

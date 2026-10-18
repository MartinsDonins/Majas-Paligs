<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\SurveyRoundStatus;
use App\Livewire\Heating\RoundCard;
use App\Models\AuditEntry;
use App\Models\HeatingReport;
use App\Models\RiserStatus;
use App\Models\SurveyRound;
use App\Services\Heating\SurveyRoundService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * HEAT-003 — survey rounds: scope rules, reports attach to the open round, round projections, Home progress card,
 * 7-day rolling window for ad-hoc reports (AC 10).
 */
beforeEach(function (): void {
    $this->b = heatingHouse('round-house', 2, 3);
    $this->admin = makeUser();
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->elder = makeUser();
    grantRole($this->elder, $this->b, BuildingRoleType::SectionElder, 1);
});

function makeRound(object $test, ?array $sections, string $status = 'open', $user = null): SurveyRound
{
    return inBuilding($test->b, fn () => app(SurveyRoundService::class)->save($user ?? $test->admin, [
        'title_lv' => 'Novembra apkures pārbaude',
        'title_ru' => 'Ноябрьская проверка отопления',
        'section_ids' => $sections,
        'starts_at' => now('Europe/Riga')->subDay()->format('Y-m-d H:i'),
        'ends_at' => now('Europe/Riga')->addDays(6)->format('Y-m-d H:i'),
        'status' => $status,
    ]));
}

it('lets building_admin create whole-building rounds and elders only rounds of their own stairwell', function (): void {
    $whole = makeRound($this, null);
    expect($whole->section_ids)->toBeNull();

    $own = makeRound($this, [section($this->b, 1)->id], 'draft', $this->elder);
    expect($own->section_ids)->toBe([section($this->b, 1)->id]);

    expect(fn () => makeRound($this, null, 'draft', $this->elder))->toThrow(AuthorizationException::class)
        ->and(fn () => makeRound($this, [section($this->b, 2)->id], 'draft', $this->elder))->toThrow(AuthorizationException::class);

    expect(inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'survey_round.save')->count()))->toBe(2);
});

it('attaches reports to the open round covering the stairwell and projects round:{id}', function (): void {
    $round = makeRound($this, [section($this->b, 1)->id]);

    residentReports($this->b, 1, 'cold');   // stairwell 1 → round
    residentReports($this->b, 13, 'cold');  // stairwell 2 → ad-hoc

    $rows = inBuilding($this->b, fn () => HeatingReport::query()->get()->groupBy(fn ($r) => (int) $r->survey_round_id));
    expect($rows->get($round->id))->toHaveCount(3)
        ->and($rows->get(0))->toHaveCount(3);

    $roundRows = inBuilding($this->b, fn () => RiserStatus::query()->where('round_key', 'round:'.$round->id)->where('distinct_apartments', '>', 0)->count());
    expect($roundRows)->toBe(3);

    // The rolling window still contains both stairwells.
    expect(residentSectionView($this->b, 1)->column(1)->distinctApartments)->toBe(1)
        ->and(residentSectionView($this->b, 2)->column(1)->distinctApartments)->toBe(1)
        ->and(residentSectionView($this->b, 1, 'round:'.$round->id)->column(1)->distinctApartments)->toBe(1)
        ->and(residentSectionView($this->b, 2, 'round:'.$round->id)->column(1)->distinctApartments)->toBe(0);
});

it('shows the Home round card with progress for my stairwell', function (): void {
    makeRound($this, [section($this->b, 1)->id]);
    $me = makeUser();
    member($me, $this->b, 2);
    residentReports($this->b, 1, 'warm');

    inBuilding($this->b, fn () => Livewire::actingAs($me)->test(RoundCard::class)
        ->assertSee('Novembra apkures pārbaude')
        ->assertSee(__('heating.round.progress', ['filled' => 1, 'total' => 12]))
        ->assertSee(__('heating.round.fill')));

    reportHeating($me, $this->b, 2, ['kitchen' => 'warm']);
    inBuilding($this->b, fn () => Livewire::actingAs($me)->test(RoundCard::class)->assertSee(__('heating.round.done')));
});

it('closes a round: new reports are ad-hoc again', function (): void {
    $round = makeRound($this, null);
    inBuilding($this->b, fn () => app(SurveyRoundService::class)->transition($this->admin, $round, SurveyRoundStatus::Closed));

    residentReports($this->b, 1, 'cold');
    expect(inBuilding($this->b, fn () => HeatingReport::query()->whereNotNull('survey_round_id')->count()))->toBe(0);
});

it('drops ad-hoc reports from the rolling view after 7 days (AC 10)', function (): void {
    residentReports($this->b, 1, 'cold');
    residentReports($this->b, 5, 'cold');
    expect(residentSectionView($this->b, 1)->column(1)->displayState())->toBe('cold');

    $this->travel(8)->days();
    $this->artisan('heating:recompute')->assertSuccessful();

    expect(residentSectionView($this->b, 1)->column(1)->displayState())->toBe('none');
});

it('rejects an end before the start', function (): void {
    expect(fn () => inBuilding($this->b, fn () => app(SurveyRoundService::class)->save($this->admin, [
        'title_lv' => 'X', 'title_ru' => 'X', 'section_ids' => null, 'starts_at' => '2026-11-08 10:00', 'ends_at' => '2026-11-01 10:00',
    ])))->toThrow(ValidationException::class);
});

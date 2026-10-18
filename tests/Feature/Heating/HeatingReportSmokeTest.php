<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\IncidentKind;
use App\Gdpr\AccountEraser;
use App\Gdpr\GdprExporter;
use App\Livewire\Heating\Grid;
use App\Livewire\Heating\ReportSheet;
use App\Models\HeatingReport;
use App\Services\Heating\SurveyRoundService;
use App\Services\Outages\IncidentService;
use Livewire\Livewire;

/*
 * End-to-end smoke of Package B at HTTP / Livewire level (MVP-SPEC B.8 "Browser/HeatingReportSmokeTest" — a real
 * browser run needs pest-plugin-browser + Playwright, tracked in TEST-001.1): round → Home card → report sheet →
 * staff grid → manager PDF; plus GDPR export / erasure of heating data.
 */
it('walks the heating flow from the Home card to the manager PDF', function (): void {
    $b = heatingHouse('smoke-heat', 1, 9);
    $admin = makeUser();
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $elder = makeUser();
    member($elder, $b, 2);
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);
    $resident = makeUser();
    member($resident, $b, 1);

    inBuilding($b, fn () => app(SurveyRoundService::class)->save($admin, [
        'title_lv' => 'Novembra pārbaude', 'title_ru' => 'Ноябрьская проверка', 'section_ids' => null,
        'starts_at' => now('Europe/Riga')->subHour()->format('Y-m-d H:i'), 'ends_at' => now('Europe/Riga')->addDays(5)->format('Y-m-d H:i'), 'status' => 'open',
    ]));

    // Home shows the round card with the fill link.
    $home = $this->actingAs($resident)->get('/b/smoke-heat')->assertOk()->assertSee('Novembra pārbaude')->getContent();
    expect($home)->toContain('report=1');

    // Heating page opens the report sheet; the resident reports in 3 taps.
    $this->actingAs($resident)->get('/b/smoke-heat/heating?report=1')->assertOk()->assertSee('data-open="1"', false);
    inBuilding($b, fn () => Livewire::actingAs($resident)->test(ReportSheet::class)->call('allSame', 'cold')->call('save')->assertSee(__('heating.report.thanks')));
    residentReports($b, 5, 'cold');

    // The elder of this stairwell sees the staff grid and downloads the manager PDF (step-up → signed URL).
    steppedUp($elder);
    $this->actingAs($elder)->get('/b/smoke-heat/heating')->assertOk()->assertSee(__('heating.staff.badge'))->assertSee(__('heating.manager.download'));

    app(StepUpService::class)->markFresh($elder, 'email');
    $component = inBuilding($b, fn () => Livewire::actingAs($elder)->test(Grid::class, ['mode' => 'heating'])->call('managerReport'));
    $url = $component->effects['redirect'] ?? null;
    expect($url)->toContain('/b/smoke-heat/reports/')->toContain('signature=');

    $this->actingAs($elder)->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('includes my heating reports in my export and keeps them as house data after erasure', function (): void {
    $b = heatingHouse('smoke-gdpr', 1, 2);
    $me = residentReports($b, 1, 'cold');
    inBuilding($b, fn () => app(IncidentService::class)->report($me, apartment($b, 1), IncidentKind::WaterHot));

    $payload = (new GdprExporter('unused'))->collect($me);
    expect($payload['buildings'][0]['heating_reports'])->toHaveCount(3)
        ->and($payload['buildings'][0]['outage_confirmations'])->toHaveCount(1);

    app(AccountEraser::class)->erase($me);

    $rows = inBuilding($b, fn () => HeatingReport::query()->get());
    expect($rows)->toHaveCount(3)->and($rows->every(fn ($r) => $r->reported_by_user_id === $me->id))->toBeTrue()
        ->and($me->refresh()->isTombstone())->toBeTrue();
});

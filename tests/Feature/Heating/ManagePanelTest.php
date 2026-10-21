<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\IncidentKind;
use App\Enums\ManagerReportKind;
use App\Services\Heating\ManagerReportGenerator;
use App\Services\Heating\SectionScope;
use App\Services\Heating\SurveyRoundService;
use App\Services\Outages\IncidentService;

/*
 * Filament `manage` panel — Package B resources render for building_admin, and elders see only their own stairwells
 * (survey rounds, manager reports, heating reports); residents stay out.
 */
beforeEach(function (): void {
    $this->b = heatingHouse('panel-heat', 2, 2);
    $this->admin = makeUser();
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->elder = makeUser();
    grantRole($this->elder, $this->b, BuildingRoleType::SectionElder, 2);
});

it('renders every Package B page for building_admin', function (): void {
    residentReports($this->b, 1, 'cold');
    inBuilding($this->b, fn () => app(IncidentService::class)->postNotice($this->admin, IncidentKind::WaterCold, [
        'target' => 'building', 'reason_lv' => 'Remonts', 'reason_ru' => 'Ремонт',
    ]));
    inBuilding($this->b, fn () => app(ManagerReportGenerator::class)->generate($this->admin, ManagerReportKind::Heating, SectionScope::fromIds([section($this->b, 1)->id])));

    steppedUp($this->admin);
    foreach (['survey-rounds', 'incidents', 'manager-reports', 'riser-disputes', 'heating-reports', 'risers'] as $page) {
        $this->actingAs($this->admin)->get("/admin/b/panel-heat/{$page}")->assertOk();
    }
    $this->actingAs($this->admin)->get('/admin/b/panel-heat/manager-reports')->assertSee('MP-');
});

it('shows elders only their own stairwell in rounds, reports and heating reports', function (): void {
    $s1 = section($this->b, 1)->id;
    $s2 = section($this->b, 2)->id;
    inBuilding($this->b, fn () => app(SurveyRoundService::class)->save($this->admin, ['title_lv' => 'Kārta-viens', 'title_ru' => 'Обход-1', 'section_ids' => [$s1], 'starts_at' => '2026-11-01 08:00', 'ends_at' => '2026-11-08 08:00']));
    inBuilding($this->b, fn () => app(SurveyRoundService::class)->save($this->admin, ['title_lv' => 'Kārta-divi', 'title_ru' => 'Обход-2', 'section_ids' => [$s2], 'starts_at' => '2026-11-01 08:00', 'ends_at' => '2026-11-08 08:00']));
    $r1 = inBuilding($this->b, fn () => app(ManagerReportGenerator::class)->generate($this->admin, ManagerReportKind::Heating, SectionScope::fromIds([$s1])));
    $r2 = inBuilding($this->b, fn () => app(ManagerReportGenerator::class)->generate($this->elder, ManagerReportKind::Heating, SectionScope::fromIds([$s2])));

    steppedUp($this->elder);
    $this->actingAs($this->elder)->get('/admin/b/panel-heat/survey-rounds')->assertOk()->assertSee('Kārta-divi')->assertDontSee('Kārta-viens');
    $this->actingAs($this->elder)->get('/admin/b/panel-heat/manager-reports')->assertOk()->assertSee($r2->number)->assertDontSee($r1->number);
});

it('keeps plain residents out of the panel', function (): void {
    $resident = makeUser();
    member($resident, $this->b, 1);
    steppedUp($resident);

    $this->actingAs($resident)->get('/admin/b/panel-heat/heating-reports')->assertForbidden();
});

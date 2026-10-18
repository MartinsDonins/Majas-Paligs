<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\IncidentKind;
use App\Enums\IncidentStatus;
use App\Livewire\Elder\NoticeComposer;
use App\Livewire\Outage\Banner;
use App\Models\AuditEntry;
use App\Models\Incident;
use App\Services\Outages\IncidentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * AC 7 — an elder notice (riser / column / stairwell, reason, expected end) shows a banner only to affected
 * apartments, in LV and RU. Elders only for their own stairwell; the whole building only for building_admin.
 */
beforeEach(function (): void {
    $this->b = heatingHouse('notice-house', 2, 3);
    $this->elder = makeUser();
    grantRole($this->elder, $this->b, BuildingRoleType::SectionElder, 1);
    $this->admin = makeUser();
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);

    $this->residents = [];
    foreach ([1, 2, 3, 6, 13] as $n) {
        $u = makeUser();
        member($u, $this->b, $n);
        $this->residents[$n] = $u;
    }
});

function noticeBanner(object $t, int $apartment, string $locale = 'lv')
{
    app()->setLocale($locale);

    return inBuilding($t->b, fn () => Livewire::actingAs($t->residents[$apartment])->test(Banner::class));
}

it('targets a single riser', function (): void {
    $riser = riserId($this->b, 'CW1-K'); // left side of stairwell 1: positions 1–2
    inBuilding($this->b, fn () => app(IncidentService::class)->postNotice($this->elder, IncidentKind::WaterCold, [
        'target' => 'riser', 'riser_id' => $riser, 'reason_lv' => 'Stāvada remonts', 'reason_ru' => 'Ремонт стояка', 'expected_end_at' => '2026-12-01 18:00',
    ]));

    noticeBanner($this, 1)->assertSee('Stāvada remonts')->assertSee(__('outage.banner.expected', ['time' => '01.12. 18:00']));
    noticeBanner($this, 2)->assertSee('Stāvada remonts');
    noticeBanner($this, 3)->assertDontSee('Stāvada remonts');
    noticeBanner($this, 13)->assertDontSee('Stāvada remonts');
    noticeBanner($this, 6, 'ru')->assertSee('Ремонт стояка')->assertDontSee('Stāvada remonts');
});

it('targets a column and a floor range', function (): void {
    inBuilding($this->b, fn () => app(IncidentService::class)->postNotice($this->elder, IncidentKind::WaterHot, [
        'target' => 'column', 'section_id' => section($this->b, 1)->id, 'position' => 2, 'floors_from' => 2, 'floors_to' => 3,
        'reason_lv' => 'Kolonnas darbi', 'reason_ru' => 'Работы в колонне',
    ]));

    noticeBanner($this, 6)->assertSee('Kolonnas darbi');  // floor 2, position 2
    noticeBanner($this, 2)->assertDontSee('Kolonnas darbi'); // floor 1, position 2 → outside the floor range
    noticeBanner($this, 1)->assertDontSee('Kolonnas darbi');
});

it('targets a stairwell and, for building_admin, the whole building', function (): void {
    inBuilding($this->b, fn () => app(IncidentService::class)->postNotice($this->elder, IncidentKind::HeatingNone, [
        'target' => 'section', 'section_id' => section($this->b, 1)->id, 'reason_lv' => 'Siltummezgls', 'reason_ru' => 'Тепловой узел',
    ]));
    noticeBanner($this, 3)->assertSee('Siltummezgls');
    noticeBanner($this, 13)->assertDontSee('Siltummezgls');

    inBuilding($this->b, fn () => app(IncidentService::class)->postNotice($this->admin, IncidentKind::WaterCold, [
        'target' => 'building', 'reason_lv' => 'Visa māja', 'reason_ru' => 'Весь дом',
    ]));
    noticeBanner($this, 13)->assertSee('Visa māja');

    expect(inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'incident.notice')->count()))->toBe(2);
});

it('refuses an elder notice for another stairwell or the whole building', function (): void {
    expect(fn () => inBuilding($this->b, fn () => app(IncidentService::class)->postNotice($this->elder, IncidentKind::WaterCold, [
        'target' => 'section', 'section_id' => section($this->b, 2)->id, 'reason_lv' => 'X', 'reason_ru' => 'X',
    ])))->toThrow(AuthorizationException::class)
        ->and(fn () => inBuilding($this->b, fn () => app(IncidentService::class)->postNotice($this->elder, IncidentKind::WaterCold, [
            'target' => 'building', 'reason_lv' => 'X', 'reason_ru' => 'X',
        ])))->toThrow(AuthorizationException::class)
        ->and(fn () => inBuilding($this->b, fn () => app(IncidentService::class)->postNotice($this->elder, IncidentKind::WaterCold, [
            'target' => 'riser', 'riser_id' => riserId($this->b, 'CW2-K'), 'reason_lv' => 'X', 'reason_ru' => 'X',
        ])))->toThrow(AuthorizationException::class);
});

it('requires both languages', function (): void {
    expect(fn () => inBuilding($this->b, fn () => app(IncidentService::class)->postNotice($this->elder, IncidentKind::WaterCold, [
        'target' => 'section', 'section_id' => section($this->b, 1)->id, 'reason_lv' => 'Tikai LV', 'reason_ru' => '',
    ])))->toThrow(ValidationException::class);
});

it('posts and resolves through the elder notice composer', function (): void {
    inBuilding($this->b, fn () => Livewire::actingAs($this->elder)->test(NoticeComposer::class)
        ->set('kind', 'water_cold')->set('target', 'section')->set('stairwell', 1)
        ->set('reasonLv', 'Avārija pagrabā')->set('reasonRu', 'Авария в подвале')
        ->call('post')->assertHasNoErrors()->assertSet('posted', true));

    $notice = inBuilding($this->b, fn () => Incident::query()->firstOrFail());
    noticeBanner($this, 1)->assertSee('Avārija pagrabā');

    inBuilding($this->b, fn () => Livewire::actingAs($this->elder)->test(NoticeComposer::class)->call('resolve', $notice->id));
    expect($notice->refresh()->is_open)->toBeFalse();
    noticeBanner($this, 1)->assertDontSee('Avārija pagrabā');
});

it('shows the outage detail page with the manager reply in one sentence', function (): void {
    $incident = inBuilding($this->b, fn () => app(IncidentService::class)->postNotice($this->admin, IncidentKind::WaterHot, [
        'target' => 'section', 'section_id' => section($this->b, 1)->id, 'reason_lv' => 'Remonts', 'reason_ru' => 'Ремонт',
    ]));
    inBuilding($this->b, fn () => app(IncidentService::class)->update($this->admin, $incident, IncidentStatus::InProgress, 'RNP-123', ['lv' => 'Remonts līdz 18:00', 'ru' => 'Ремонт до 18:00']));

    $this->actingAs($this->residents[1])->get("/b/notice-house/incidents/{$incident->id}")
        ->assertOk()->assertSee('Remonts līdz 18:00')->assertSee(__('enum.incident_status.in_progress'))->assertSee(__('shell.not_emergency'));
});

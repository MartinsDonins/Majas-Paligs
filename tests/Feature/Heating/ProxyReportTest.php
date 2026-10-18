<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\ReportSource;
use App\Livewire\Elder\ProxyReport;
use App\Models\AuditEntry;
use App\Models\HeatingReport;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
 * HEAT-008 — proxy / phone reports: an elder (or volunteer) of THAT stairwell only; marked with the source and
 * audit-logged.
 */
beforeEach(function (): void {
    $this->b = heatingHouse('proxy-house', 2, 3);
    $this->elder = makeUser();
    grantRole($this->elder, $this->b, BuildingRoleType::SectionElder, 1);
});

it('lets the elder report for an apartment of the own stairwell (phone), audit-logged', function (): void {
    steppedUp($this->elder);

    inBuilding($this->b, fn () => Livewire::actingAs($this->elder)->test(ProxyReport::class)
        ->set('apartmentNumber', 5)
        ->set('source', 'phone')
        ->set('states.kitchen', 'cold')
        ->set('states.room1', 'lukewarm')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('savedFor', 5));

    $rows = inBuilding($this->b, fn () => HeatingReport::query()->get());
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($r) => $r->source === ReportSource::Phone && $r->reported_by_user_id === $this->elder->id))->toBeTrue()
        ->and(inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'heating.proxy_report')->count()))->toBe(1);
});

it('refuses another stairwell (403) and offers only own apartments', function (): void {
    expect(fn () => reportHeating($this->elder, $this->b, 13, ['kitchen' => 'cold'], ReportSource::Proxy))
        ->toThrow(AuthorizationException::class);

    inBuilding($this->b, fn () => Livewire::actingAs($this->elder)->test(ProxyReport::class)
        ->assertViewHas('apartments', fn ($list) => $list === range(1, 12)));
});

it('lets a volunteer of the stairwell report by proxy, but not a plain resident', function (): void {
    $volunteer = makeUser();
    grantRole($volunteer, $this->b, BuildingRoleType::Volunteer, 2);
    reportHeating($volunteer, $this->b, 13, ['kitchen' => 'warm'], ReportSource::Proxy);

    $resident = makeUser();
    member($resident, $this->b, 1);
    expect(fn () => reportHeating($resident, $this->b, 5, ['kitchen' => 'cold'], ReportSource::Proxy))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => reportHeating($resident, $this->b, 5, ['kitchen' => 'cold']))
        ->toThrow(AuthorizationException::class); // not their apartment

    expect(inBuilding($this->b, fn () => HeatingReport::query()->count()))->toBe(1);
});

it('shows the proxy, notice and digest tabs in the elder panel when the modules are on', function (): void {
    steppedUp($this->elder);

    $this->actingAs($this->elder)->get('/b/proxy-house/help?tab=proxy')->assertOk()->assertSee(__('elder.proxy.title'));
    $this->actingAs($this->elder)->get('/b/proxy-house/help?tab=notice')->assertOk()->assertSee(__('elder.notice.title'));
    $this->actingAs($this->elder)->get('/b/proxy-house/help?tab=digest')->assertOk()->assertSee(__('digest.card.title'))->assertSee('https://wa.me/?text=', false);
});

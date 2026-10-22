<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\BuildingStatus;
use App\Enums\MembershipStatus;
use App\Gdpr\CoreGate;
use App\Gdpr\CoreGateMonitor;
use App\Livewire\Me\LinkMyself;
use App\Models\ApartmentMembership;
use App\Models\AuditEntry;
use App\Models\Building;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * FIRSTRUN-001 — no blank pages: every empty or blocked state says WHY and offers the next step (resident home of a
 * closed house, locked module cards and pages, the admin's "Sākt šeit", self-link for an admin without apartment,
 * heating + outages switched on at the first opening, parking lists below L1). Synthetic data only.
 */

beforeEach(function (): void {
    Notification::fake();
});

it('tells a member of a closed house that it is not open yet — never "Šobrīd viss kārtībā" — and shows locked module cards', function (): void {
    $b = makeBuilding('closed-house', 1, 2);
    $admin = makeUser();
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    // A pending member of an earlier opened, now regressed house sees the same calm explanation.
    $resident = makeUser();
    member($resident, $b, 1, MembershipStatus::Pending);
    $b->forceFill(['settings' => $b->settings->with(['dpa_ref' => null])])->save();

    $this->actingAs($resident)->get('/b/closed-house')->assertOk()
        ->assertSee('data-testid="house-closed"', false)
        ->assertSee(__('start.home.closed_resident'))
        ->assertDontSee(__('home.status_calm'))
        ->assertSee('data-testid="tile-locked-heating"', false)
        ->assertSee('data-testid="tile-locked-outages"', false)
        ->assertSee('data-testid="tile-locked-parking"', false)
        ->assertSee(__('start.locked.house_closed.short'))
        ->assertDontSee(__('start.locked.house_closed.fix'));

    $this->actingAs($admin)->get('/b/closed-house')->assertOk()
        ->assertSee(__('start.home.closed_admin'))
        ->assertSee('data-testid="house-closed-continue"', false)
        ->assertSee(url('/admin/b/closed-house/opening'), false)
        // The admin holds a role but no apartment here: the way to link himself.
        ->assertSee('data-testid="home-no-apartment"', false);
});

it('explains a switched-off module on its card and on its page (still a 404), with the fix for the admin only', function (): void {
    $b = makeBuilding('off-house', 1, 2, open: true);
    $resident = makeUser();
    member($resident, $b, 1);
    $admin = makeUser();
    member($admin, $b, 2);
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    $this->actingAs($resident)->get('/b/off-house')->assertOk()
        ->assertSee(__('home.status_calm'))
        ->assertSee('data-reason="module_off"', false)
        ->assertSee('data-reason="parking_gate"', false);

    $this->actingAs($resident)->get('/b/off-house/heating')->assertNotFound()
        ->assertSee('data-testid="module-locked"', false)
        ->assertSee(__('start.locked.module_off.title'))
        ->assertDontSee('data-testid="module-fix"', false);
    $this->actingAs($resident)->get('/b/off-house/parking')->assertNotFound()
        ->assertSee(__('start.locked.parking_gate.title'));

    $this->actingAs($admin)->get('/b/off-house/water')->assertNotFound()
        ->assertSee('data-testid="module-fix"', false)
        ->assertSee(url('/admin/b/off-house/settings'), false);
    $this->actingAs($admin)->get('/b/off-house/parking')->assertNotFound()
        ->assertSee(url('/admin/b/off-house/parking-status'), false);

    // Strangers still get the plain anti-enumeration 404 (member middleware runs first).
    $this->actingAs(makeUser())->get('/b/off-house/heating')->assertNotFound()->assertDontSee('data-testid="module-locked"', false);
});

it('switches heating + outages on and makes the house active at its FIRST opening, audited', function (): void {
    $b = makeBuilding('first-open', 1, 2);
    expect($b->status)->toBe(BuildingStatus::Draft);

    $before = inBuilding($b, fn (Building $x) => CoreGate::status($x));
    openGate($b);
    inBuilding($b, fn (Building $x) => CoreGateMonitor::afterChange($x, $before));

    $b->refresh();
    expect($b->settings->moduleEnabled('heating'))->toBeTrue()
        ->and($b->settings->moduleEnabled('outages'))->toBeTrue()
        ->and($b->settings->moduleEnabled('parking'))->toBeFalse()
        ->and($b->status)->toBe(BuildingStatus::Active)
        ->and(AuditEntry::query()->where('building_id', $b->id)->where('action', 'opening.first_open')->exists())->toBeTrue();

    // A later re-opening never overrides the admin's choice.
    $b->forceFill(['settings' => $b->settings->with(['modules' => ['heating' => false, 'outages' => false]])])->save();
    $closed = inBuilding($b, fn (Building $x) => CoreGate::status($x));
    inBuilding($b, fn (Building $x) => CoreGateMonitor::afterChange($x, $closed));
    expect($b->refresh()->settings->moduleEnabled('heating'))->toBeFalse();
});

it('keeps an admin choice made before the first opening', function (): void {
    $b = makeBuilding('chosen-open', 1, 2);
    $b->forceFill(['settings' => $b->settings->with(['modules' => ['heating' => true]])])->save();

    $before = inBuilding($b, fn (Building $x) => CoreGate::status($x));
    openGate($b);
    inBuilding($b, fn (Building $x) => CoreGateMonitor::afterChange($x, $before));

    expect($b->refresh()->settings->moduleEnabled('outages'))->toBeFalse()
        ->and($b->settings->moduleEnabled('heating'))->toBeTrue();
});

it('gives the building admin "Sākt šeit" with a status and one link per step', function (): void {
    $b = makeBuilding('steps-house', 2, 2);
    $admin = makeUser();
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $elder = makeUser();
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);

    steppedUp($admin, 'totp');
    $html = (string) $this->actingAs($admin)->get('/admin/b/steps-house')->assertOk()
        ->assertSee(__('start.steps.heading'))
        ->getContent();

    foreach (['admin' => 'done', 'opening' => 'todo', 'modules' => 'blocked', 'team' => 'optional', 'roster' => 'blocked', 'permits' => 'blocked', 'letters' => 'blocked', 'me' => 'blocked'] as $step => $status) {
        expect($html)->toContain('data-testid="step-'.$step.'" data-status="'.$status.'"');
    }
    expect($html)->toContain('/admin/b/steps-house/opening')
        ->and($html)->toContain('/admin/b/steps-house/team');

    // An elder's dashboard keeps the gate cards only.
    $this->flushSession();
    steppedUp($elder, 'email');
    $this->actingAs($elder)->get('/admin/b/steps-house')->assertOk()->assertDontSee('data-testid="getting-started"', false);
});

it('lets a building admin without an apartment request his own — pending, with who approves it and the invite link', function (): void {
    $b = makeBuilding('self-house', 2, 2, open: true);
    $admin = makeUser();
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    $this->actingAs($admin)->get('/b/self-house/me')->assertOk()->assertSee('data-testid="link-myself"', false);

    $this->actingAs($admin);
    session()->put(StepUpService::SESSION_KEY, ['user' => $admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'totp']);
    inBuilding($b, fn () => Livewire::test(LinkMyself::class)
        ->set('apartmentNumber', '6')
        ->call('link')
        ->assertRedirect(route('building.me', ['building' => 'self-house'])));

    $m = inBuilding($b, fn () => ApartmentMembership::query()->where('user_id', $admin->id)->firstOrFail());
    expect($m->status)->toBe(MembershipStatus::Pending)
        ->and(AuditEntry::query()->where('action', 'membership.self_link')->where('reason_code', 'self_request')->exists())->toBeTrue();

    $this->actingAs($admin)->get('/b/self-house/me')->assertOk()
        ->assertSee('data-testid="self-pending"', false)
        ->assertSee(__('start.self.invite_elder', ['n' => 1]))
        ->assertDontSee('data-testid="link-myself"', false);

    // The helper count no longer counts his own request ("Palīdzība kaimiņiem · 1 pieteikums").
    $this->actingAs($admin)->get('/b/self-house')->assertOk()
        ->assertDontSee(trans_choice('home.tools.pending', 1, ['count' => 1]));

    // Without a fresh second factor the component asks for one first.
    $other = makeUser();
    grantRole($other, $b, BuildingRoleType::BuildingAdmin);
    $this->actingAs($other);
    session()->forget(StepUpService::SESSION_KEY);
    inBuilding($b, fn () => Livewire::test(LinkMyself::class)->set('apartmentNumber', '5')->call('link')->assertRedirect(route('step-up')));
});

it('sends parking staff from a parking list below L1 to "Stāvvietas statuss" instead of a bare 403', function (): void {
    $b = makeBuilding('l0-house', 1, 2, open: true);
    $admin = makeUser();
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    steppedUp($admin, 'totp');

    $this->actingAs($admin)->get('/admin/b/l0-house/permits')->assertRedirect(url('/admin/b/l0-house/parking-status'));
});

it('titles the roster list like its menu item', function (): void {
    $b = makeBuilding('title-house', 1, 2, open: true);
    $admin = makeUser();
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    steppedUp($admin, 'totp');

    $this->actingAs($admin)->get('/admin/b/title-house/roster')->assertOk()->assertSee('<h1', false)->assertSee(__('roster.title'));
});

<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Support\ViewSwitch;

/*
 * FIRSTRUN-001 — "Iedzīvotāja skats ⇄ Admin skats": shown only to someone who can administer something, in the
 * resident top bar and in both panels; deep-links to the same house; remembers the last view (mp_view).
 */

beforeEach(function (): void {
    $this->b = makeBuilding('view-house', 1, 2, open: true);
});

it('shows the toggle to an elder and an admin in the resident app, never to a plain resident', function (): void {
    $elder = makeUser();
    member($elder, $this->b, 1);
    grantRole($elder, $this->b, BuildingRoleType::SectionElder, 1);
    $resident = makeUser();
    member($resident, $this->b, 2);

    $this->actingAs($elder)->get('/b/view-house')->assertOk()
        ->assertSee('data-testid="view-switch"', false)
        ->assertSee('data-view="resident"', false)
        ->assertSee(__('start.view.admin'))
        ->assertSee(route('view.switch', ['mode' => 'admin', 'b' => 'view-house']), false);

    $this->actingAs($resident)->get('/b/view-house')->assertOk()
        ->assertDontSee('data-testid="view-switch"', false)
        ->assertDontSee(__('start.view.admin'));
    $this->actingAs($resident)->get('/b/view-house/me')->assertOk()->assertDontSee('data-testid="view-switch"', false);
});

it('opens the same house in the admin view and remembers it', function (): void {
    $admin = makeUser();
    grantRole($admin, $this->b, BuildingRoleType::BuildingAdmin);

    $this->actingAs($admin)->get('/view/admin?b=view-house')
        ->assertRedirect('/admin/b/view-house')
        ->assertCookie(ViewSwitch::COOKIE, 'admin');

    $this->actingAs($admin)->get('/view/resident?b=view-house')
        ->assertRedirect(route('building.home', ['building' => 'view-house'], absolute: false))
        ->assertCookie(ViewSwitch::COOKIE, 'resident');

    // An unknown or malformed slug falls back to the generic entry (no probing for houses).
    $this->actingAs($admin)->get('/view/admin?b=nav-tadas')->assertRedirect(route('admin.entry', absolute: false));
    $this->actingAs($admin)->get('/view/admin?b=../x')->assertRedirect(route('admin.entry', absolute: false));
});

it('sends a platform_admin without a role in the house to its platform page, and a resident nowhere', function (): void {
    $platform = makeUser(attributes: ['platform_role' => 'platform_admin']);
    $resident = makeUser();
    member($resident, $this->b, 3);

    $this->actingAs($platform)->get('/view/admin?b=view-house')->assertRedirect(route('admin.house', ['building' => 'view-house'], absolute: false));
    $this->actingAs($platform)->get('/view/resident?b=view-house')->assertRedirect(route('home.redirect', absolute: false));

    $this->actingAs($resident)->get('/view/admin?b=view-house')->assertRedirect(route('home.redirect'))->assertCookieMissing(ViewSwitch::COOKIE);
});

it('lets /home follow a remembered admin view, but only for someone who can still administer', function (): void {
    $admin = makeUser();
    member($admin, $this->b, 4);
    grantRole($admin, $this->b, BuildingRoleType::BuildingAdmin);
    $resident = makeUser();
    member($resident, $this->b, 5);

    $this->actingAs($admin)->withCookie(ViewSwitch::COOKIE, 'admin')->get('/home')->assertRedirect(route('admin.entry'));
    $this->actingAs($admin)->withCookie(ViewSwitch::COOKIE, 'resident')->get('/home')->assertRedirect(route('building.home', ['building' => 'view-house']));
    $this->actingAs($resident)->withCookie(ViewSwitch::COOKIE, 'admin')->get('/home')->assertRedirect(route('building.home', ['building' => 'view-house']));
});

it('shows "Iedzīvotāja skats" in the house panel (same house) and in the platform panel', function (): void {
    $platform = makeUser(attributes: ['platform_role' => 'platform_admin']);
    grantRole($platform, $this->b, BuildingRoleType::BuildingAdmin);
    steppedUp($platform, 'totp');

    $this->actingAs($platform)->get('/admin/b/view-house')->assertOk()
        ->assertSee('data-testid="panel-view-switch"', false)
        ->assertSee('data-view="admin"', false)
        ->assertSee(e(route('view.switch', ['mode' => 'resident', 'b' => 'view-house'])), false);

    $this->actingAs($platform)->get('/admin/platform')->assertOk()
        ->assertSee('data-testid="panel-view-switch"', false)
        ->assertSee(route('view.switch', ['mode' => 'resident']), false);

    // The /admin chooser is an admin screen in the resident shell: the toggle points back to the resident view.
    $this->actingAs($platform)->get('/admin')->assertOk()->assertSee('data-view="admin"', false);
});

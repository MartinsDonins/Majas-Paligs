<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Tenancy\CurrentBuilding;

/*
 * /admin entry (ROUND2-SPEC §1.1, AC-R2-01).
 */

beforeEach(function (): void {
    $this->a = makeBuilding('entry-a', 1, 2, open: true);
    $this->b = makeBuilding('entry-b', 1, 2);
});

it('sends someone with exactly one manage role straight to that building panel', function (): void {
    $admin = makeUser();
    grantRole($admin, $this->a, BuildingRoleType::BuildingAdmin);

    $this->actingAs($admin)->get('/admin')->assertRedirect('/admin/b/entry-a');
    expect(CurrentBuilding::has())->toBeFalse();
});

it('counts a section elder and a parking officer as administrators too', function (BuildingRoleType $role): void {
    $user = makeUser();
    grantRole($user, $this->a, $role, $role === BuildingRoleType::SectionElder ? 1 : null);

    $this->actingAs($user)->get('/admin')->assertRedirect('/admin/b/entry-a');
})->with([BuildingRoleType::SectionElder, BuildingRoleType::ParkingOfficer]);

it('shows a chooser with one card per building (roles, gate summary) when there are two', function (): void {
    $user = makeUser();
    grantRole($user, $this->a, BuildingRoleType::BuildingAdmin);
    grantRole($user, $this->b, BuildingRoleType::SectionElder, 1);

    $html = (string) $this->actingAs($user)->get('/admin')->assertOk()
        ->assertSee(__('admin.entry.title'))
        ->assertSee($this->a->name)
        ->assertSee($this->b->name)
        ->assertSee(__('enum.building_role.building_admin'))
        ->assertSee(__('enum.building_role.section_elder'))
        ->assertSee(__('admin.entry.core_open'))
        ->assertSee(__('admin.entry.core_closed'))
        ->assertDontSee('data-admin-target="platform"', false)
        ->getContent();

    expect($html)->toContain('href="'.url('/admin/b/entry-a').'"')
        ->and($html)->toContain('href="'.url('/admin/b/entry-b').'"')
        ->and(CurrentBuilding::has())->toBeFalse();
});

it('lists only buildings where the user has a role — never a membership-only house', function (): void {
    $user = makeUser();
    grantRole($user, $this->a, BuildingRoleType::BuildingAdmin);
    $platform = makeUser(attributes: ['platform_role' => 'platform_admin']);
    member($user, $this->b, 1);

    $this->actingAs($user)->get('/admin')->assertRedirect('/admin/b/entry-a');

    $this->actingAs($platform)->get('/admin')->assertRedirect('/admin/platform');
});

it('offers the platform next to the houses for a platform_admin with a building role', function (): void {
    $platform = makeUser(attributes: ['platform_role' => 'platform_admin']);
    grantRole($platform, $this->a, BuildingRoleType::BuildingAdmin);

    $this->actingAs($platform)->get('/admin')->assertOk()
        ->assertSee('data-admin-target="platform"', false)
        ->assertSee('data-admin-target="entry-a"', false)
        ->assertDontSee('data-admin-target="entry-b"', false);
});

it('sends someone without any administration role home with a message', function (): void {
    $resident = makeUser();
    member($resident, $this->a, 1);
    $volunteer = makeUser();
    grantRole($volunteer, $this->a, BuildingRoleType::Volunteer);

    $this->actingAs($resident)->get('/admin')->assertRedirect(route('home.redirect'));
    $this->get(route('home.redirect'))->assertRedirect(route('building.home', ['building' => 'entry-a']));
    $this->get('/b/entry-a')->assertOk()->assertSee(__('admin.entry.no_role'));

    $this->actingAs($volunteer)->get('/admin')->assertRedirect(route('home.redirect'));
});

it('asks a guest to sign in', function (): void {
    $this->get('/admin')->assertRedirect(route('login'));
});

it('sends a platform_admin without a house role from the bare /admin/b back to /admin', function (): void {
    $platform = makeUser(attributes: ['platform_role' => 'platform_admin']);
    $resident = makeUser();
    member($resident, $this->a, 1);

    $this->actingAs($platform)->get('/admin/b')->assertRedirect(route('admin.entry'));
    // Every other panel URL keeps the normal 403 / 404.
    $this->actingAs($resident)->get('/admin/b/entry-a')->assertForbidden();
});

it('serves the chooser with the strict resident CSP and the panels with the Filament exception only', function (): void {
    $user = makeUser(attributes: ['platform_role' => 'platform_admin']);
    grantRole($user, $this->a, BuildingRoleType::BuildingAdmin);
    steppedUp($user, 'totp');

    $entry = $this->actingAs($user)->get('/admin')->assertOk();
    expect($entry->headers->get('Content-Security-Policy'))->not->toContain('unsafe-eval')
        ->and($entry->headers->get('Cache-Control'))->toContain('no-store');

    $panel = $this->actingAs($user)->get('/admin/b/entry-a')->assertOk();
    expect($panel->headers->get('Content-Security-Policy'))->toContain('unsafe-eval');
});

it('links the panels back to /admin (brand, tenant menu "Visas mājas", platform user menu) and to the resident app', function (): void {
    $user = makeUser(attributes: ['platform_role' => 'platform_admin']);
    grantRole($user, $this->a, BuildingRoleType::BuildingAdmin);
    steppedUp($user, 'totp');

    $this->actingAs($user)->get('/admin/b/entry-a')->assertOk()
        ->assertSee(__('admin.brand'))
        ->assertSee(__('admin.entry.all_buildings'))
        ->assertSee(__('admin.entry.back_to_app'))
        ->assertSee('href="'.route('admin.entry').'"', false);

    $this->actingAs($user)->get('/admin/platform')->assertOk()
        ->assertSee(__('admin.entry.house_admin'));
});

it('shows "Administrēšana" in the resident "Vairāk" page to an elder and an admin, not to a resident', function (): void {
    $elder = makeUser();
    member($elder, $this->a, 1);
    grantRole($elder, $this->a, BuildingRoleType::SectionElder, 1);
    $resident = makeUser();
    member($resident, $this->a, 2);

    $this->actingAs($elder)->get('/b/entry-a/me')->assertOk()
        ->assertSee(__('home.tools.administration'))
        ->assertSee('href="'.route('admin.entry').'"', false);
    $this->actingAs($resident)->get('/b/entry-a/me')->assertOk()
        ->assertDontSee(__('home.tools.administration'));
});

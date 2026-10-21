<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Models\Building;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
 * AC-R2-03 — building panel navigation per role × parking gate level (ROUND2-SPEC §1.2): below L1 the Stāvvieta group
 * shows only "Stāvvietas statuss" + "Zīme" for admin / officer (never hidden — R2) and nothing parking-related for
 * elders; at L1 the parking resources appear. Komanda and Mājas atvēršana are visible to every manage role.
 */

function navUser(Building $b, BuildingRoleType $role, string $email): User
{
    $user = makeUser($email);
    grantRole($user, $b, $role, $role === BuildingRoleType::SectionElder ? 1 : null);

    return $user;
}

function dashboardFor(User $user, Building $b): TestResponse
{
    steppedUp($user);

    return test()->actingAs($user)->get('/admin/b/'.$b->slug)->assertOk();
}

dataset('parking levels', ['L0' => [0], 'L1' => [1]]);

it('shows admins and officers the parking status and the sign below L1, the register from L1', function (int $level, BuildingRoleType $role): void {
    $slug = 'nav-'.str_replace('_', '-', $role->value).'-'.$level;
    $b = $level === 0 ? makeBuilding($slug, 1, 1, open: true) : parkingHouse($slug, level: 1);
    $user = navUser($b, $role, "{$slug}@example.test");

    $page = dashboardFor($user, $b)
        ->assertSee(__('opening.parking_status.title'))
        ->assertSee(__('admin.parking.sign.nav'))
        ->assertSee(__('admin.team.title'))
        ->assertSee(__('opening.title'));

    $level === 0
        ? $page->assertDontSee(__('admin.parking.permit.plural'))
        : $page->assertSee(__('admin.parking.permit.plural'));
})->with('parking levels')->with([
    'building_admin' => [BuildingRoleType::BuildingAdmin],
    'parking_officer' => [BuildingRoleType::ParkingOfficer],
]);

it('shows elders nothing parking-related at any level, but Komanda (read) and Mājas atvēršana', function (int $level): void {
    $b = $level === 0 ? makeBuilding('nav-elder-'.$level, 1, 1, open: true) : parkingHouse('nav-elder-'.$level, level: 1);
    $elder = navUser($b, BuildingRoleType::SectionElder, "nav-elder-{$level}@example.test");

    dashboardFor($elder, $b)
        ->assertDontSee(__('opening.parking_status.title'))
        ->assertDontSee(__('admin.parking.sign.nav'))
        ->assertDontSee(__('admin.parking.permit.plural'))
        ->assertSee(__('admin.team.title'))
        ->assertSee(__('opening.title'));
})->with('parking levels');

it('keeps the grant-from-members resource off the navigation but reachable from Komanda', function (): void {
    $b = makeBuilding('nav-roles', 1, 1, open: true);
    $admin = navUser($b, BuildingRoleType::BuildingAdmin, 'nav-roles@example.test');

    dashboardFor($admin, $b)->assertDontSee('/admin/b/nav-roles/building-roles"', false);
    $this->actingAs($admin)->get('/admin/b/nav-roles/team')->assertOk()->assertSee(__('admin.team.grant_existing'));
});

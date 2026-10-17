<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->a = makeBuilding('fil-a', 1, 2, open: true);
    $this->b = makeBuilding('fil-b', 1, 2, open: true);
    $this->adminA = makeUser('admin-a@example.test');
    grantRole($this->adminA, $this->a, BuildingRoleType::BuildingAdmin);
    $this->resident = makeUser('resident-a@example.test');
    member($this->resident, $this->a, 1);
});

it('resolves tenants without a context and without throwing', function (): void {
    $panel = Filament::getPanel('manage');

    expect(CurrentBuilding::has())->toBeFalse()
        ->and($this->adminA->getTenants($panel)->pluck('slug')->all())->toBe(['fil-a'])
        ->and($this->adminA->canAccessTenant($this->a))->toBeTrue()
        ->and($this->adminA->canAccessTenant($this->b))->toBeFalse()
        ->and($this->adminA->getDefaultTenant($panel)?->slug)->toBe('fil-a')
        ->and($this->resident->getTenants($panel))->toBeEmpty()
        ->and($this->resident->canAccessPanel($panel))->toBeFalse()
        ->and(CurrentBuilding::has())->toBeFalse();
});

it('opens /manage/{own building} for the admin after step-up and sets the tenant context', function (): void {
    steppedUp($this->adminA);

    $this->actingAs($this->adminA)->get('/manage/fil-a')->assertOk();
    $this->actingAs($this->adminA)->get('/manage/fil-a/apartments')->assertOk()->assertSee('9');
    $this->actingAs($this->adminA)->get('/manage/fil-a/settings')->assertOk()->assertSee(__('admin.gate.core'));
    $this->actingAs($this->adminA)->get('/manage/fil-a/invites')->assertOk();
    $this->actingAs($this->adminA)->get('/manage/fil-a/memberships')->assertOk();
    $this->actingAs($this->adminA)->get('/manage/fil-a/audit-entries')->assertOk();
});

it('requires step-up for the manage panel', function (): void {
    $this->actingAs($this->adminA)->get('/manage/fil-a')->assertRedirect(route('step-up'));
});

it('returns 404 for another building and never throws MissingBuildingContext', function (): void {
    steppedUp($this->adminA);

    $this->actingAs($this->adminA)->get('/manage/fil-b')->assertNotFound();
    $this->actingAs($this->adminA)->get('/manage/fil-b/apartments')->assertNotFound();
});

it('keeps resident-only members out of /manage', function (): void {
    steppedUp($this->resident);

    $this->actingAs($this->resident)->get('/manage/fil-a')->assertForbidden();
});

it('keeps non-platform-admins out of /platform and lets a TOTP-stepped-up admin in', function (): void {
    steppedUp($this->adminA);
    $this->actingAs($this->adminA)->get('/platform')->assertForbidden();

    $platform = makeUser('platform@example.test', ['platform_role' => 'platform_admin']);
    steppedUp($platform, 'totp');
    $this->actingAs($platform)->get('/platform')->assertOk();
    $this->actingAs($platform)->get('/platform/buildings')->assertOk()->assertSee('fil-a');
    $this->actingAs($platform)->get('/platform/building-series')->assertOk();
    $this->actingAs($platform)->get('/platform/generator')->assertOk();
    $this->actingAs($platform)->get('/platform/audit')->assertOk();

    $resident = User::query()->find($this->resident->id);
    steppedUp($resident, 'totp');
    $this->actingAs($resident)->get('/platform')->assertForbidden();
});

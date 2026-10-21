<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Filament\Manage\Pages\TeamPage;
use App\Models\BuildingRole;
use App\Services\Memberships\BuildingRoleService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Last-admin guard and self-handover (ROUND2-SPEC §2.2 item 4, AC-R2-06).
 */

beforeEach(function (): void {
    Notification::fake();
    $this->b = makeBuilding('guard-house', 1, 2);
    $this->admin = makeUser('guard-admin@example.test');
    $this->role = grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->actingAs($this->admin);
    session()->put(StepUpService::SESSION_KEY, ['user' => $this->admin->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'passkey']);
});

it('refuses to remove the last building_admin — service, policy and page', function (): void {
    expect(fn () => inBuilding($this->b, fn () => app(BuildingRoleService::class)->revoke($this->admin, $this->role)))
        ->toThrow(ValidationException::class);
    expect(inBuilding($this->b, fn () => $this->admin->can('revoke', $this->role)))->toBeFalse()
        ->and(inBuilding($this->b, fn () => $this->role->refresh()->revoked_at))->toBeNull();

    inBuilding($this->b, fn () => Livewire::test(TeamPage::class)->assertDontSee(__('admin.team.resign')));
});

it('lets the platform override the guard (platform panel only)', function (): void {
    inBuilding($this->b, fn () => app(BuildingRoleService::class)->revoke(null, $this->role, platformOverride: true));

    expect(inBuilding($this->b, fn () => $this->role->refresh()->revoked_at))->not->toBeNull();
});

it('hands over: once a second admin exists, the first may give up their own role', function (): void {
    $next = makeUser('guard-next@example.test');
    $nextRole = grantRole($next, $this->b, BuildingRoleType::BuildingAdmin);

    inBuilding($this->b, function (): void {
        Livewire::test(TeamPage::class)
            ->assertSee(__('admin.team.resign'))
            ->callAction('remove', arguments: ['id' => $this->role->id, 'self' => true])
            ->assertRedirect(route('admin.entry'));
    });

    expect(inBuilding($this->b, fn () => $this->role->refresh()->revoked_at))->not->toBeNull()
        // …and now the remaining admin is the last one again.
        ->and(inBuilding($this->b, fn () => $next->can('revoke', $nextRole)))->toBeFalse();
});

it('lets an elder resign without touching the admin guard, and an admin remove an elder', function (): void {
    $elder = makeUser('guard-elder@example.test');
    $elderRole = grantRole($elder, $this->b, BuildingRoleType::SectionElder, 1);

    expect(inBuilding($this->b, fn () => $elder->can('revoke', $elderRole)))->toBeTrue()
        ->and(inBuilding($this->b, fn () => $this->admin->can('revoke', $elderRole)))->toBeTrue();

    inBuilding($this->b, fn () => Livewire::test(TeamPage::class)->callAction('remove', arguments: ['id' => $elderRole->id])
        ->assertNotified(__('admin.team.removed')));

    expect(inBuilding($this->b, fn () => BuildingRole::query()->current()->where('user_id', $elder->id)->exists()))->toBeFalse();
});

it('re-confirms a role for 12 months with an mfa step-up', function (): void {
    $elder = makeUser('guard-reconfirm@example.test');
    $elderRole = grantRole($elder, $this->b, BuildingRoleType::SectionElder, 1);
    inBuilding($this->b, fn () => $elderRole->forceFill(['expires_at' => now()->addDays(10)])->save());

    inBuilding($this->b, fn () => Livewire::test(TeamPage::class)->callAction('reconfirm', arguments: ['id' => $elderRole->id])
        ->assertNotified(__('admin.team.reconfirmed')));

    expect(inBuilding($this->b, fn () => $elderRole->refresh()->expires_at->isAfter(now()->addMonths(11))))->toBeTrue();
});

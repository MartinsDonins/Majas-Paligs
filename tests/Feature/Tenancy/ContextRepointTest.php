<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Models\AuditEntry;
use App\Platform\PlatformBuildingContext;
use App\Platform\PlatformPurpose;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\BuildingContextAlreadySet;
use Illuminate\Auth\Access\AuthorizationException;

it('throws when code inside a request tries to re-point the context', function (): void {
    $a = makeBuilding('rp-a', 1, 1);
    $b = makeBuilding('rp-b', 1, 1);

    CurrentBuilding::set($a);
    CurrentBuilding::set($b);
})->throws(BuildingContextAlreadySet::class);

it('allows only Seed in console without a user, audits purpose + building and restores the context', function (): void {
    $a = makeBuilding('rp-c', 1, 1);

    $seen = PlatformBuildingContext::run($a, PlatformPurpose::Seed, fn () => CurrentBuilding::id());

    $entry = AuditEntry::query()->platformChain()->where('action', 'platform.building_context')->latest('id')->first();

    expect($seen)->toBe($a->id)
        ->and(CurrentBuilding::has())->toBeFalse()
        ->and($entry->data)->toBe(['purpose' => 'seed', 'building_id' => $a->id]);

    expect(fn () => PlatformBuildingContext::run($a, PlatformPurpose::Generate, fn () => null))->toThrow(AuthorizationException::class);
});

it('refuses non-platform-admins and platform admins without a fresh TOTP step-up', function (): void {
    $a = makeBuilding('rp-d', 1, 1);
    $resident = makeUser();
    $admin = makeUser(attributes: ['platform_role' => 'platform_admin']);

    $this->actingAs($resident);
    expect(fn () => PlatformBuildingContext::run($a, PlatformPurpose::Generate, fn () => null))->toThrow(AuthorizationException::class);

    $this->actingAs($admin);
    expect(fn () => PlatformBuildingContext::run($a, PlatformPurpose::Generate, fn () => null))->toThrow(AuthorizationException::class);

    session()->put(StepUpService::SESSION_KEY, ['user' => $admin->id, 'at' => now()->getTimestamp(), 'method' => 'email']);
    expect(fn () => PlatformBuildingContext::run($a, PlatformPurpose::Generate, fn () => null))->toThrow(AuthorizationException::class);

    session()->put(StepUpService::SESSION_KEY, ['user' => $admin->id, 'at' => now()->getTimestamp(), 'method' => 'totp']);
    expect(PlatformBuildingContext::run($a, PlatformPurpose::Generate, fn () => 'ok'))->toBe('ok');
});

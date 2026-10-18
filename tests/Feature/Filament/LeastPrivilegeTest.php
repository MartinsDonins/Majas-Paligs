<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Filament\Manage\Pages\BuildingSettingsPage;
use App\Filament\Manage\Resources\ApartmentResource;
use App\Filament\Manage\Resources\MembershipResource;
use App\Filament\Platform\Resources\BuildingResource;
use App\Gdpr\CoreGate;
use App\Models\AuditEntry;
use App\Models\Building;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
 * Review fixes (drošība, 0.0.12): a section elder sees only their own stairwells in the manage panel, and the DPA
 * fields that open gate L-core / parking L1 are recorded only by the platform (CoreDigify = processor).
 */

it('limits section elders to the memberships and apartments of their own stairwell (list + badge)', function (): void {
    $b = makeBuilding('lp-house', 2, 2, open: true); // stairwell 1 = apts 1–8, stairwell 2 = apts 9–16
    $admin = makeUser('lp-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $elder = makeUser('lp-elder@example.test');
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);

    $own = member(makeUser(), $b, 3, MembershipStatus::Pending);
    $foreign = member(makeUser(), $b, 12, MembershipStatus::Pending);

    $this->actingAs($elder);
    inBuilding($b, function () use ($own, $foreign): void {
        expect(MembershipResource::getEloquentQuery()->pluck('id')->all())->toContain($own->id)->not->toContain($foreign->id)
            ->and(MembershipResource::getNavigationBadge())->toBe('1')
            ->and(ApartmentResource::getEloquentQuery()->pluck('number')->sort()->values()->all())->toBe(range(1, 8));
    });

    $this->actingAs($admin);
    inBuilding($b, fn () => expect(MembershipResource::getEloquentQuery()->count())->toBe(2)
        ->and(MembershipResource::getNavigationBadge())->toBe('2')
        ->and(ApartmentResource::getEloquentQuery()->count())->toBe(16));
});

it('shows the DPA read-only in the manage panel: a building_admin cannot open the gate by typing it', function (): void {
    $b = makeBuilding('lp-dpa', 1, 1);
    openGate($b);
    $b->forceFill(['settings' => $b->settings->with(['dpa_signed_at' => null, 'dpa_ref' => null, 'dpa_scope' => []])])->save();
    $admin = makeUser('lp-dpa-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($b);
    app(StepUpService::class)->markFresh($admin, 'totp');

    inBuilding($b, fn () => Livewire::actingAs($admin)->test(BuildingSettingsPage::class)
        ->set('data.dpa_signed_at', '2026-10-01')
        ->set('data.dpa_ref', 'SELF-SIGNED')
        ->set('data.dpa_scope', ['core', 'heating', 'outages', 'parking'])
        ->call('save'));

    $b->refresh();
    expect($b->settings->dpaRef)->toBeNull()->and($b->settings->dpaScope)->toBe([])
        ->and(inBuilding($b, fn (Building $x) => CoreGate::status($x)->open))->toBeFalse();
});

it('records the DPA only from the platform (platform_admin + TOTP), audited, and then opens L-core', function (): void {
    $b = makeBuilding('lp-dpa-platform', 1, 1);
    openGate($b);
    $b->forceFill(['settings' => $b->settings->with(['dpa_signed_at' => null, 'dpa_ref' => null, 'dpa_scope' => []])])->save();
    $data = ['dpa_signed_at' => '2026-10-15', 'dpa_ref' => 'DPA-2026-001', 'dpa_scope' => ['core', 'heating', 'outages']];

    $admin = makeUser('lp-not-platform@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $this->actingAs($admin);
    app(StepUpService::class)->markFresh($admin, 'totp');
    expect(fn () => BuildingResource::recordDpa($b, $data))->toThrow(AuthorizationException::class);

    $platform = makeUser('lp-platform@example.test', ['platform_role' => 'platform_admin']);
    $this->actingAs($platform);
    app(StepUpService::class)->markFresh($platform, 'email');
    expect(fn () => BuildingResource::recordDpa($b, $data))->toThrow(AuthorizationException::class);

    app(StepUpService::class)->markFresh($platform, 'totp');
    BuildingResource::recordDpa($b, $data);

    $b = Building::query()->findOrFail($b->id);
    expect($b->settings->dpaRef)->toBe('DPA-2026-001')
        ->and(inBuilding($b, fn (Building $x) => CoreGate::status($x)->open))->toBeTrue()
        ->and(inBuilding($b, fn () => AuditEntry::query()->where('action', 'settings.dpa')->count()))->toBe(1);
});

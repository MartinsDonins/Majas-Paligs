<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\MembershipRole;
use App\Gdpr\CoreGate;
use App\Gdpr\CoreGateClosed;
use App\Gdpr\CoreGateMonitor;
use App\Livewire\Onboarding\ChooseRole;
use App\Livewire\Onboarding\RedeemInvite;
use App\Models\ApartmentInvite;
use App\Models\ApartmentMembership;
use App\Models\AuditEntry;
use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Notifications\CoreGateChanged;
use App\Services\Onboarding\JoinState;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * AC 18 — gate L-core: matrix over each missing condition; every write path refused while closed.
 */
function breakGate(Building $b, string $condition): void
{
    $settings = $b->settings;

    match ($condition) {
        'controller name' => $b->forceFill(['settings' => $settings->with(['controller' => ['name' => null]])])->save(),
        'controller contact' => $b->forceFill(['settings' => $settings->with(['controller' => ['contact' => null]])])->save(),
        'dpa_signed_at' => $b->forceFill(['settings' => $settings->with(['dpa_signed_at' => null])])->save(),
        'dpa_ref' => $b->forceFill(['settings' => $settings->with(['dpa_ref' => null])])->save(),
        'dpa_scope without heating' => $b->forceFill(['settings' => $settings->with(['dpa_scope' => ['core', 'outages']])])->save(),
        'no notice' => inBuilding($b, fn () => BuildingPrivacyNotice::query()->delete()),
        'notice not covering outages' => inBuilding($b, fn () => BuildingPrivacyNotice::query()->update(['covers_modules' => '{core,heating}'])),
        'withdrawn notice' => inBuilding($b, fn () => BuildingPrivacyNotice::query()->update(['withdrawn_at' => now()])),
    };
}

$conditions = ['controller name', 'controller contact', 'dpa_signed_at', 'dpa_ref', 'dpa_scope without heating', 'no notice', 'notice not covering outages', 'withdrawn notice'];

it('is open only when every condition holds', function (string $condition): void {
    $b = makeBuilding('gate-'.md5($condition), 1, 1, open: true);
    expect(inBuilding($b, fn () => CoreGate::status($b)->open))->toBeTrue();

    breakGate($b->refresh(), $condition);

    $status = inBuilding($b->refresh(), fn (Building $x) => CoreGate::status($x));
    expect($status->open)->toBeFalse()->and($status->missing)->not->toBeEmpty();
})->with($conditions);

it('refuses /join redemption below L-core with the neutral screen and no membership', function (string $condition): void {
    $b = makeBuilding('gate-join-'.md5($condition), 1, 1, open: true);
    $code = makeInvite($b, 1);
    breakGate($b->refresh(), $condition);

    Livewire::test(RedeemInvite::class)->set('code', $code)->call('submit')->assertSet('closed', true)->assertNoRedirect();

    expect(ApartmentMembership::withoutBuildingScope()->count())->toBe(0);
})->with($conditions);

it('refuses membership creation from any path (model guard, incl. console)', function (): void {
    $b = makeBuilding('gate-guard', 1, 1);
    $user = makeUser();

    inBuilding($b, fn () => ApartmentMembership::query()->create([
        'apartment_id' => apartment($b, 1)->id, 'user_id' => $user->id, 'role' => MembershipRole::Owner, 'status' => 'pending',
    ]));
})->throws(CoreGateClosed::class);

it('disables invite printing (InvitePolicy) while closed', function (): void {
    $b = makeBuilding('gate-print', 1, 1);
    $admin = makeUser();
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    expect(inBuilding($b, fn () => Gate::forUser($admin)->allows('print', ApartmentInvite::class)))->toBeFalse();

    openGate($b);
    expect(inBuilding($b->refresh(), fn () => Gate::forUser($admin)->allows('print', ApartmentInvite::class)))->toBeTrue();
});

it('stores the building notice id on the membership when open', function (): void {
    $b = makeBuilding('gate-notice', 1, 1, open: true);
    $notice = inBuilding($b, fn () => BuildingPrivacyNotice::current());
    $code = makeInvite($b, 2);

    Livewire::test(RedeemInvite::class)->set('code', $code)->call('submit');
    Livewire::test(ChooseRole::class)
        ->assertSee(__('onboarding.notice.title'))
        ->assertSee('Testa biedrība');
    expect(session(JoinState::KEY)['invite']['building_notice_id'])->toBe($notice->id);
});

it('closes again on regression: audited and alerted', function (): void {
    Notification::fake();
    $b = makeBuilding('gate-regress', 1, 1, open: true);
    $admin = makeUser('gadmin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    inBuilding($b, function (Building $x): void {
        $before = CoreGate::status($x);
        $x->forceFill(['settings' => $x->settings->with(['dpa_ref' => null])])->save();
        CoreGateMonitor::afterChange($x, $before);
    });

    expect(AuditEntry::query()->forBuilding($b->id)->where('action', 'gate.core.closed')->exists())->toBeTrue();
    Notification::assertSentTo($admin, CoreGateChanged::class, fn ($n) => $n->open === false && in_array('dpa_ref', $n->missing, true));
});

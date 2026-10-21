<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Livewire\Elder\ApprovalQueue;
use App\Livewire\Onboarding\ChooseAccess;
use App\Livewire\Onboarding\ChooseRole;
use App\Livewire\Onboarding\RedeemInvite;
use App\Models\ApartmentInvite;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Notifications\MembershipDecided;
use App\Notifications\MembershipRequested;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function joinWithPin(string $typedCode, string $pin = '958372'): Testable
{
    Livewire::test(RedeemInvite::class)->set('code', $typedCode)->call('submit')->assertRedirect(route('join.role'));
    Livewire::test(ChooseRole::class)
        ->set('role', 'owner')->set('adult', true)->set('noticesAccepted', true)
        ->call('choose')->assertRedirect(route('join.access'));

    return Livewire::test(ChooseAccess::class)->call('pick', 'pin')->set('pin', $pin)->set('pinConfirm', $pin)->call('usePin');
}

it('accepts codes typed lowercase, with spaces or with Cyrillic look-alikes', function (callable $mutate): void {
    $building = makeBuilding('join-a', 1, 2, open: true);
    $code = makeInvite($building, 5);

    Livewire::test(RedeemInvite::class)->set('code', $mutate($code))->call('submit')->assertRedirect(route('join.role'));
})->with([
    'lowercase' => [fn (string $c) => strtolower($c)],
    'spaces' => [fn (string $c) => implode(' ', str_split($c, 2))],
    'cyrillic' => [fn (string $c) => strtr($c, ['K' => 'К', 'M' => 'М', 'P' => 'Р', 'C' => 'С', 'X' => 'Х'])],
]);

it('shows the friendly error for an invalid code and a calm cooldown once the wrong-code budget is used up', function (): void {
    makeBuilding('join-b', 1, 1, open: true);
    $component = Livewire::test(RedeemInvite::class);

    for ($i = 1; $i <= (int) config('majas.invites.throttle.ip_per_15min'); $i++) {
        $component->set('code', 'ABCDE23456')->call('submit')->assertSet('error', __('onboarding.code.invalid'))->assertSet('cooldown', false);
    }

    $component->set('code', 'ABCDE23456')->call('submit')->assertSet('cooldown', true)->assertSee(__('onboarding.cooldown.title'));
});

it('hints at ambiguous characters', function (): void {
    Livewire::test(RedeemInvite::class)->set('code', 'O0OOO-11111')->call('submit')->assertSet('error', __('onboarding.code.ambiguous'));
});

it('creates a PENDING membership, consumes one use, stores the notice and notifies elder + members', function (): void {
    Notification::fake();
    $building = makeBuilding('join-c', 1, 2, open: true);
    $elder = makeUser('elder@example.test');
    grantRole($elder, $building, BuildingRoleType::SectionElder, 1);
    $neighbour = makeUser('neighbour@example.test');
    member($neighbour, $building, 5);

    $code = makeInvite($building, 5);
    joinWithPin($code)->assertSet('createdHandle', fn ($h) => is_string($h) && str_starts_with($h, '5-'));

    $user = User::query()->whereNotNull('login_handle')->firstOrFail();
    $membership = inBuilding($building, fn () => ApartmentMembership::query()->where('user_id', $user->id)->firstOrFail());

    expect($membership->status)->toBe(MembershipStatus::Pending)
        ->and($membership->building_notice_id)->not->toBeNull()
        ->and(inBuilding($building, fn () => ApartmentInvite::query()->first()->uses))->toBe(1)
        ->and($user->is_adult_confirmed_at)->not->toBeNull()
        ->and(auth()->id())->toBe($user->id);

    Notification::assertSentTo($elder, MembershipRequested::class, fn ($n) => $n->forElder === true && $n->apartmentNumber === 5);
    Notification::assertSentTo($neighbour, MembershipRequested::class, fn ($n) => $n->forElder === false);
});

it('respects max_uses, expiry and revocation', function (): void {
    $building = makeBuilding('join-d', 1, 1, open: true);
    $code = makeInvite($building, 1, ['max_uses' => 1]);

    joinWithPin($code);
    auth()->logout();
    CurrentBuilding::forget();

    Livewire::test(RedeemInvite::class)->set('code', $code)->call('submit')->assertSet('error', __('onboarding.code.invalid'));
});

it('lets the elder of that stairwell approve in one tap and notifies the requester', function (): void {
    Notification::fake();
    $building = makeBuilding('join-e', 2, 2, open: true);
    $elder = makeUser('elder2@example.test');
    grantRole($elder, $building, BuildingRoleType::SectionElder, 1);
    $requester = makeUser('req@example.test');
    $membership = member($requester, $building, 3, MembershipStatus::Pending);

    inBuilding($building, function () use ($elder, $membership): void {
        Livewire::actingAs($elder)->test(ApprovalQueue::class)
            ->assertSee(__('onboarding.role.apartment', ['n' => 3]))
            ->call('approve', $membership->id);

        expect($membership->refresh()->status)->toBe(MembershipStatus::Approved);
    });

    Notification::assertSentTo($requester, MembershipDecided::class, fn ($n) => $n->approved);
});

it('shows the neutral closed screen when the house is not open (no membership created)', function (): void {
    $building = makeBuilding('join-f', 1, 1);
    $code = makeInvite($building, 2);

    Livewire::test(RedeemInvite::class)->set('code', $code)->call('submit')
        ->assertSet('closed', true)
        ->assertSee(__('onboarding.closed.title'))
        ->assertNoRedirect();

    expect(ApartmentMembership::withoutBuildingScope()->count())->toBe(0);
});

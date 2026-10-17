<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Livewire\Elder\ApprovalQueue;
use App\Livewire\Onboarding\ChooseAccess;
use App\Livewire\Onboarding\ChooseRole;
use App\Livewire\Onboarding\RedeemInvite;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * End-to-end onboarding (code → role → e-mail → confirm → pending → elder approves) on the HTTP/Livewire level.
 * The real-browser variant (Browser/OnboardingSmokeTest with Mailpit) needs pest-plugin-browser + Playwright and
 * is tracked as a follow-up (BRAIN/TASK.md TEST-001).
 */
it('walks a resident from the mailbox code to an approved membership', function (): void {
    Notification::fake();
    $b = makeBuilding('smoke', 1, 3, open: true);
    $elder = makeUser('smoke-elder@example.test');
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);
    $code = makeInvite($b, 9);

    $this->get('/join')->assertOk()->assertSee(__('onboarding.code.title'));

    Livewire::test(RedeemInvite::class)->set('code', strtolower($code))->call('submit')->assertRedirect(route('join.role'));
    Livewire::test(ChooseRole::class)->assertSee(__('onboarding.role.apartment', ['n' => 9]))
        ->set('role', 'tenant')->set('adult', true)->set('noticesAccepted', true)->call('choose')
        ->assertRedirect(route('join.access'));
    Livewire::test(ChooseAccess::class)->call('pick', 'email')->set('email', 'smoke-resident@example.test')->call('useEmail')
        ->assertSet('mailSent', true);

    $user = User::query()->where('email', 'smoke-resident@example.test')->firstOrFail();
    $code6 = null;
    Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $n) use (&$code6): bool {
        $code6 = $n->code();

        return true;
    });

    // The login completes the pending /join stored in this session.
    $this->withSession(session()->all())
        ->post('/login/code', ['email' => 'smoke-resident@example.test', 'code' => $code6])
        ->assertRedirect(route('building.home', ['building' => 'smoke']));

    $membership = inBuilding($b, fn () => ApartmentMembership::query()->where('user_id', $user->id)->firstOrFail());
    expect($membership->status)->toBe(MembershipStatus::Pending)
        ->and($user->refresh()->email_verified_at)->not->toBeNull();

    $this->actingAs($user)->get('/b/smoke')->assertOk()->assertSee(__('onboarding.pending.title'));

    inBuilding($b, fn () => Livewire::actingAs($elder)->test(ApprovalQueue::class)->call('approve', $membership->id));
    expect($membership->refresh()->status)->toBe(MembershipStatus::Approved);

    $this->actingAs($user)->get('/b/smoke')->assertOk()->assertDontSee(__('onboarding.pending.title'));
});

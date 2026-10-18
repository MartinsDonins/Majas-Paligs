<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Models\ApartmentMembership;
use App\Models\User;

/*
 * MVP-SPEC A.12 "Browser/OnboardingSmokeTest": the real resident journey in Chromium with the real Vite build,
 * the CSP Livewire/Alpine bundle and real SMTP into Mailpit — mailbox code → role + notices → e-mail → 6-digit
 * code from the Mailpit message → pending Home → the stairwell elder steps up (e-mail code, Mailpit) and approves
 * → the resident's Home is no longer pending. Synthetic data only (@example.test, fictional building).
 */
it('walks a resident from the mailbox code to an approved membership in a real browser', function (): void {
    useMailpit();
    $b = makeBuilding('smoke', 1, 3, open: true);
    $elderEmail = browserEmail('elder');
    $elder = makeUser($elderEmail);
    member($elder, $b, 1);
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);
    $code = makeInvite($b, 9);
    $email = browserEmail('resident');

    // 1. Code from the letter (typed in lower case — the input normalises).
    $page = phone('/join');
    $page->assertSee(__('onboarding.code.title'))
        ->type('code', strtolower($code))
        ->press(__('common.continue'))
        ->waitForText(__('onboarding.role.title'));
    $page->assertPathIs('/join/role')
        ->assertSee(__('onboarding.role.apartment', ['n' => 9]))
        ->assertSee(__('onboarding.notice.title'))   // Art. 13 notice before role choice (AC 18)
        ->assertNoJavaScriptErrors();

    // 2. Role + adult + notices.
    $page->radio('role', 'tenant')
        ->check('input[wire\:model="adult"]')
        ->check('input[wire\:model="noticesAccepted"]')
        ->press(__('common.continue'))
        ->waitForText(__('onboarding.access.title'));
    $page->assertPathIs('/join/access');

    // 3. E-mail access → the code arrives in Mailpit.
    $page->click(__('onboarding.access.email'))
        ->waitForText(__('auth.email.send'));
    $page->type('email', $email)
        ->press(__('auth.email.send'))
        ->waitForText(__('auth.email.sent_title'));

    $page->type('code', mailpitCode($email))
        ->press(__('auth.login'))
        ->waitForText(__('onboarding.pending.title'));
    $page->assertPathIs('/b/smoke')->assertNoJavaScriptErrors();

    $user = User::query()->where('email', $email)->firstOrFail();
    $membership = inBuilding($b, fn () => ApartmentMembership::query()->where('user_id', $user->id)->firstOrFail());
    expect($membership->status)->toBe(MembershipStatus::Pending)
        ->and($user->email_verified_at)->not->toBeNull();

    // 4. The elder of stairwell 1 opens the help panel: step-up by e-mail code (Mailpit), then approves.
    $this->actingAs($elder);
    $elderPage = phone('/b/smoke/help');
    $elderPage->waitForText(__('auth.step_up.title'));
    $elderPage->press(__('auth.step_up.send'))
        ->waitForText(__('auth.step_up.confirm'));
    $elderPage->type('code', mailpitCode($elderEmail))
        ->press(__('auth.step_up.confirm'))
        ->waitForText(__('elder.approvals.title'));
    $elderPage->assertSee(__('onboarding.role.apartment', ['n' => 9]))
        ->click('button[wire\:click^="approve("]') // not the "Apstiprināt" tab with the same label
        ->waitForText(__('elder.approvals.empty'));
    $elderPage->assertNoJavaScriptErrors();

    expect($membership->refresh()->status)->toBe(MembershipStatus::Approved);

    // 5. Back as the resident: Home is no longer pending.
    $this->actingAs($user->refresh());
    $page->refresh()->waitForText(__('home.my_stairwell', ['n' => 1]));
    $page->assertDontSee(__('onboarding.pending.title'))->assertNoJavaScriptErrors();
});

it('logs a returning resident in with the 6-digit e-mail code on /login', function (): void {
    // Regression (0.0.16): the Livewire morph reused the wire:submit e-mail form element for the native POST code
    // form, and Chromium then posted only `code` (no _token / email) — every code login bounced back to /login.
    useMailpit();
    $b = makeBuilding('smoke', 1, 3, open: true);
    $email = browserEmail('returning');
    member(makeUser($email), $b, 1);

    $page = phone('/login');
    $page->type('email', $email)
        ->click('form[wire\:submit] button[type=submit]')
        ->waitForText(__('auth.email.code_label'));
    $page->type('code', mailpitCode($email))
        ->click('form[action$="/login/code"] button[type=submit]')
        ->waitForText(__('home.my_stairwell', ['n' => 1]));
    $page->assertPathIs('/b/smoke')->assertNoJavaScriptErrors();
});

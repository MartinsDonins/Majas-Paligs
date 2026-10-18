<?php

declare(strict_types=1);

use App\Auth\MagicLinkService;
use App\Auth\PinAuthService;
use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Livewire\Auth\StepUp;
use App\Livewire\Me\Security;
use App\Models\User;
use App\Notifications\LimiterAlert;
use App\Notifications\MagicLoginLink;
use App\Notifications\TotpChangedNotice;
use App\Services\Memberships\BuildingRoleService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * Review fixes (drošība, 0.0.12): the second factor cannot be replaced with the first factor alone, credential
 * changes end other sessions, cumulative e-mail code failures, TOTP step-up for building_admin / parking_officer.
 */
beforeEach(fn () => Notification::fake());

/**
 * @return array{0: User, 1: string}
 */
function totpUser(string $email, array $attributes = []): array
{
    $secret = app(Google2FA::class)->generateSecretKey(32);
    $user = makeUser($email, $attributes);
    $user->forceFill(['app_authentication_secret' => $secret])->save();

    return [$user->refresh(), $secret];
}

// ---- TOTP enrolment (Me\Security) ----

it('refuses start/confirm called directly on a user who already has TOTP (no MFA bypass)', function (): void {
    [$admin, $secret] = totpUser('pa@example.test', ['platform_role' => 'platform_admin']);
    $this->actingAs($admin);
    app(StepUpService::class)->markFresh($admin, 'email'); // the attacker controls only the mailbox / a session

    $component = Livewire::actingAs($admin)->test(Security::class)->call('start')->assertHasErrors('code');
    expect(session('mp.totp.pending'))->toBeNull();

    // Even with a planted pending secret, confirm refuses and never marks the session MFA-fresh.
    $attacker = app(Google2FA::class);
    $forged = $attacker->generateSecretKey(32);
    session()->put('mp.totp.pending', $forged);
    $component->set('code', $attacker->getCurrentOtp($forged))->call('confirm')->assertHasErrors('code');

    expect($admin->refresh()->app_authentication_secret)->toBe($secret)
        ->and(app(StepUpService::class)->hasFreshMfa($admin))->toBeFalse()
        ->and(app(StepUpService::class)->hasFresh($admin))->toBeFalse(); // an e-mail step-up never counts once TOTP exists
});

it('requires a fresh step-up before the first enrolment and never marks the session MFA-fresh', function (): void {
    $user = makeUser('first-totp@example.test');
    $this->actingAs($user);

    Livewire::actingAs($user)->test(Security::class)->call('start')->assertRedirect(route('step-up'));
    expect(session('mp.totp.pending'))->toBeNull();

    app(StepUpService::class)->markFresh($user, 'email');
    $c = Livewire::actingAs($user)->test(Security::class)->call('start');
    $pending = (string) session('mp.totp.pending');
    $c->set('code', app(Google2FA::class)->getCurrentOtp($pending))->call('confirm')->assertHasNoErrors();

    expect($user->refresh()->hasTotp())->toBeTrue()
        ->and(app(StepUpService::class)->hasFreshMfa($user))->toBeFalse()
        ->and($user->auth_epoch)->toBe(1);
    Notification::assertSentTo($user, TotpChangedNotice::class, fn (TotpChangedNotice $n) => $n->enabled);
});

it('disables TOTP only after a fresh TOTP step-up, notifies, and alerts the platform for platform_admin', function (): void {
    $watcher = makeUser('watch@example.test', ['platform_role' => 'platform_admin']);
    [$admin] = totpUser('pa2@example.test', ['platform_role' => 'platform_admin']);
    $this->actingAs($admin);

    app(StepUpService::class)->markFresh($admin, 'email');
    Livewire::actingAs($admin)->test(Security::class)->call('disable')->assertRedirect(route('step-up'));
    expect($admin->refresh()->hasTotp())->toBeTrue();

    app(StepUpService::class)->markFresh($admin, 'totp');
    Livewire::actingAs($admin)->test(Security::class)->call('disable');

    expect($admin->refresh()->hasTotp())->toBeFalse();
    Notification::assertSentTo($admin, TotpChangedNotice::class, fn (TotpChangedNotice $n) => ! $n->enabled);
    Notification::assertSentTo($watcher, LimiterAlert::class, fn (LimiterAlert $n) => $n->limiter === 'totp-changed');
});

// ---- TOTP step-up for building_admin / parking_officer ----

it('requires a TOTP step-up for building_admin and parking_officer; section elders keep the e-mail code', function (): void {
    $b = makeBuilding('mfa-roles', 1, 1, open: true);
    $admin = makeUser('mfa-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $officer = makeUser('mfa-officer@example.test');
    grantRole($officer, $b, BuildingRoleType::ParkingOfficer);
    $elder = makeUser('mfa-elder@example.test');
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);
    $stepUp = app(StepUpService::class);

    expect($stepUp->requiresMfa($admin))->toBeTrue()
        ->and($stepUp->requiresMfa($officer))->toBeTrue()
        ->and($stepUp->requiresMfa($elder))->toBeFalse();

    $this->actingAs($admin);
    $stepUp->markFresh($admin, 'email');
    expect($stepUp->hasFreshFor($admin))->toBeFalse();
    $this->get('/manage/mfa-roles')->assertRedirect(route('step-up'));
    expect(session('mp.step_up.level'))->toBe('mfa');

    $stepUp->markFresh($admin, 'totp');
    expect($stepUp->hasFreshFor($admin))->toBeTrue();

    $this->actingAs($elder);
    $stepUp->markFresh($elder, 'email');
    expect($stepUp->hasFreshFor($elder))->toBeTrue();
});

it('never accepts an e-mail step-up code for an account that has TOTP', function (): void {
    [$user] = totpUser('both@example.test');
    $this->actingAs($user);

    app(StepUpService::class)->sendEmailCode($user);
    expect(app(StepUpService::class)->verifyEmailCode($user, '123456'))->toBeFalse();
    Livewire::actingAs($user)->test(StepUp::class)->set('code', '000000')->call('verify')->assertHasErrors('code');
    expect(app(StepUpService::class)->hasFresh($user))->toBeFalse();
});

// ---- cumulative e-mail login code failures ----

it('locks code login after 10 wrong codes across fresh tokens; after 24 h a code works again', function (): void {
    $platform = makeUser('alerts@example.test', ['platform_role' => 'platform_admin']);
    $user = makeUser('target@example.test');
    $magic = app(MagicLinkService::class);
    $latestCode = function () use ($user): string {
        $code = '';
        Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $n) use (&$code): bool {
            $code = $n->code();

            return true;
        });

        return $code;
    };

    for ($round = 0; $round < 3; $round++) {
        $magic->request('target@example.test', '198.51.100.'.$round);
        $code = $latestCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 4; $i++) {
            $magic->consumeCode('target@example.test', $wrong);
        }
    }

    // 12 wrong guesses over 3 tokens (each token alone allows 5): the newest correct code is refused.
    expect($magic->consumeCode('target@example.test', $code))->toBeNull();
    Notification::assertSentTo($platform, LimiterAlert::class, fn (LimiterAlert $n) => $n->limiter === 'login-code-failures');

    $this->travel(25)->hours();
    $magic->request('target@example.test', '198.51.100.9');
    expect($magic->consumeCode('target@example.test', $latestCode())?->id)->toBe($user->id);
});

// ---- credential changes end other sessions ----

it('ends other sessions and remember cookies on an elder PIN reset', function (): void {
    $b = makeBuilding('epoch-house', 1, 1, open: true);
    $user = makeUser();
    $user->forceFill(['login_handle' => '1-EPOC'])->save();
    app(PinAuthService::class)->setPin($user, '958372', 1);
    member($user, $b, 1);
    $user->refresh();
    $epoch = (int) $user->auth_epoch;
    $oldRemember = (string) $user->remember_token;

    // A "thief" session with the current epoch works …
    $this->actingAs($user)->withSession(['mp.epoch' => $epoch])->get('/b/epoch-house')->assertOk();

    // … until the elder resets the PIN: epoch bump + remember token rotated, the old session is logged out.
    app(PinAuthService::class)->issueTempCode($user);
    $user->refresh();
    expect($user->auth_epoch)->toBe($epoch + 1)->and($user->remember_token)->not->toBe($oldRemember);

    $this->actingAs($user)->withSession(['mp.epoch' => $epoch])->get('/b/epoch-house')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('bumps the session epoch when a role is revoked', function (): void {
    $b = makeBuilding('epoch-role', 1, 1, open: true);
    $elder = makeUser('epoch-elder@example.test');
    $role = grantRole($elder, $b, BuildingRoleType::SectionElder, 1);
    $before = (int) $elder->refresh()->auth_epoch;

    inBuilding($b, fn () => app(BuildingRoleService::class)->revoke(null, $role));
    expect((int) $elder->refresh()->auth_epoch)->toBe($before + 1);
});

it('keeps a session whose epoch matches, and stamps the epoch into a session that has none', function (): void {
    $b = makeBuilding('epoch-ok', 1, 1, open: true);
    $user = makeUser('epoch-ok@example.test');
    member($user, $b, 1);

    $this->actingAs($user)->get('/b/epoch-ok')->assertOk();
    expect(session('mp.epoch'))->toBe((int) $user->refresh()->auth_epoch);
    $this->get('/b/epoch-ok')->assertOk();
});

<?php

declare(strict_types=1);

use App\Auth\LoginCompleter;
use App\Auth\Passkeys\PasskeyService;
use App\Auth\StepUpService;
use App\Enums\PlatformRole;
use App\Gdpr\AccountEraser;
use App\Livewire\Auth\EmailLogin;
use App\Livewire\Auth\PinLogin;
use App\Livewire\Auth\StepUp;
use App\Livewire\Me\PasskeyOffer;
use App\Livewire\Me\Security;
use App\Models\AuditEntry;
use App\Models\Passkey;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\SecurityChangedNotice;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakeAuthenticator;

/*
 * ADR 0006 / AUTH-006: passkeys ("Ieiet ar pirkstu / Face ID") — registration, login, step-up, and every refusal the
 * verification must make. FakeAuthenticator signs like a real phone (ES256, CBOR, "none" attestation).
 */
beforeEach(fn () => Notification::fake());

/** The value a #[Renderless] Livewire action returned. */
function returnedFrom(Testable $component, string $method): mixed
{
    $value = null;
    $component->call($method)->assertReturned(function (mixed $v) use (&$value): bool {
        $value = $v;

        return true;
    });

    return $value;
}

/** Register a passkey for $user directly through the service (session must allow it — the caller decides). */
function enrolPasskey(User $user, ?FakeAuthenticator $authenticator = null): FakeAuthenticator
{
    $authenticator ??= new FakeAuthenticator;
    $service = app(PasskeyService::class);
    $passkey = $service->register($user, $authenticator->register($service->registrationOptions($user)), 'Mozilla/5.0 (Linux; Android 14) Chrome/140.0 Mobile Safari/537.36');
    expect($passkey)->toBeInstanceOf(Passkey::class);

    return $authenticator;
}

function platformAdmin(string $email): User
{
    return makeUser($email, ['platform_role' => PlatformRole::PlatformAdmin->value]);
}

// ---- registration ----

it('adds a passkey from Ieeja un drošība after a fresh step-up, audits it and mails the account', function (): void {
    $user = makeUser('pk-add@example.test');
    $this->actingAs($user);
    steppedUp($user, 'email');

    $component = Livewire::test(Security::class);
    $options = returnedFrom($component, 'passkeyRegisterOptions');
    expect($options)->toBeString();

    $component->call('passkeyRegister', (new FakeAuthenticator)->register($options))
        ->assertHasNoErrors()
        ->assertRedirect(route('me.security', absolute: false));

    $passkey = $user->passkeys()->sole();
    expect($passkey->name)->not->toBe('')
        ->and(AuditEntry::query()->platformChain()->where('action', 'account.passkey_added')->count())->toBe(1)
        ->and(app(StepUpService::class)->hasFreshStrongMfa($user))->toBeTrue();
    Notification::assertSentTo($user, SecurityChangedNotice::class, fn (SecurityChangedNotice $n): bool => $n->kind === 'passkey_added');
});

it('sends the person to the step-up before adding a first passkey without a fresh proof', function (): void {
    $user = makeUser('pk-nostep@example.test');

    Livewire::actingAs($user)->test(Security::class)
        ->call('passkeyRegisterOptions')
        ->assertRedirect(route('step-up', absolute: false));

    expect(session(StepUpService::INTENDED_KEY))->toBe('/me/security')
        ->and(session(StepUpService::LEVEL_KEY))->toBe('any');
});

it('needs the existing second factor (not an e-mail proof) to add a passkey to an account with TOTP', function (): void {
    $user = makeUser('pk-totp@example.test');
    $user->forceFill(['app_authentication_secret' => app(Google2FA::class)->generateSecretKey(32)])->save();
    $this->actingAs($user);
    steppedUp($user, 'email');

    Livewire::test(Security::class)
        ->call('passkeyRegisterOptions')
        ->assertRedirect(route('step-up', absolute: false));
    expect(session(StepUpService::LEVEL_KEY))->toBe('strong');

    // A trusted device is not enough either; a TOTP of this session is.
    steppedUp($user, 'trusted');
    expect(app(StepUpService::class)->mayAddFactor($user))->toBeFalse();
    steppedUp($user, 'totp');
    expect(app(StepUpService::class)->mayAddFactor($user))->toBeTrue();
});

it('counts a recent login as the proof for a FIRST factor, but not an old one', function (): void {
    $user = makeUser('pk-recent@example.test');
    $this->actingAs($user)->withSession([StepUpService::LOGIN_KEY => ['user' => $user->id, 'at' => now()->getTimestamp(), 'method' => 'pin']]);
    expect(app(StepUpService::class)->mayAddFactor($user))->toBeTrue();

    $this->travel(16)->minutes();
    expect(app(StepUpService::class)->mayAddFactor($user))->toBeFalse();
});

it('refuses a registration without user verification, from another origin, or with a reused challenge', function (): void {
    $user = makeUser('pk-refuse@example.test');
    $this->actingAs($user);
    steppedUp($user, 'email');
    $service = app(PasskeyService::class);
    $agent = 'test';

    expect($service->register($user, (new FakeAuthenticator)->register($service->registrationOptions($user), userVerified: false), $agent))->toBeNull()
        ->and($service->register($user, (new FakeAuthenticator)->register($service->registrationOptions($user), origin: 'https://evil.coredigify.com'), $agent))->toBeNull()
        ->and($service->register($user, (new FakeAuthenticator)->register($service->registrationOptions($user), origin: 'http://localhost.evil.test'), $agent))->toBeNull();

    $options = $service->registrationOptions($user);
    $response = (new FakeAuthenticator)->register($options);
    expect($service->register($user, $response, $agent))->not->toBeNull()
        // the options were single-use
        ->and($service->register($user, $response, $agent))->toBeNull()
        ->and($user->passkeys()->count())->toBe(1);
});

it('refuses registration options that expired or belong to another account', function (): void {
    $user = makeUser('pk-exp@example.test');
    $other = makeUser('pk-exp-other@example.test');
    $this->actingAs($user);
    $service = app(PasskeyService::class);

    $options = $service->registrationOptions($user);
    $this->travel(6)->minutes();
    expect($service->register($user, (new FakeAuthenticator)->register($options), 'x'))->toBeNull();

    $options = $service->registrationOptions($user);
    expect($service->register($other, (new FakeAuthenticator)->register($options), 'x'))->toBeNull();
});

// ---- login ----

it('logs in with a passkey from the login page; the login counts as MFA so no TOTP prompt follows', function (): void {
    $admin = platformAdmin('pk-admin@example.test');
    $admin->forceFill(['app_authentication_secret' => app(Google2FA::class)->generateSecretKey(32)])->save();
    $this->actingAs($admin);
    steppedUp($admin, 'totp');
    $authenticator = enrolPasskey($admin);
    auth()->logout();
    session()->flush();

    $component = Livewire::test(EmailLogin::class);
    $options = returnedFrom($component, 'passkeyOptions');
    $component->call('passkeyAssert', $authenticator->assert($options))->assertHasNoErrors()->assertRedirect();

    $this->assertAuthenticatedAs($admin);
    expect(app(StepUpService::class)->hasFreshMfa($admin))->toBeTrue()
        ->and(app(StepUpService::class)->hasFreshStrongMfa($admin))->toBeTrue()
        ->and($admin->passkeys()->sole()->last_used_at)->not->toBeNull();

    // Straight into the platform panel: no /step-up.
    $this->get('/admin/platform')->assertOk();
});

it('logs in with a passkey on the PIN page too and keeps remember-me', function (): void {
    $user = makeUser(null, ['login_handle' => '145-PK7Q']);
    $this->actingAs($user)->withSession([StepUpService::LOGIN_KEY => ['user' => $user->id, 'at' => now()->getTimestamp(), 'method' => 'pin']]);
    $authenticator = enrolPasskey($user);
    auth()->logout();

    $component = Livewire::test(PinLogin::class)->set('remember', true);
    $options = returnedFrom($component, 'passkeyOptions');
    $component->call('passkeyAssert', $authenticator->assert($options))->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect(auth()->guard('web')->getCookieJar()->hasQueued('__Host-mp_remember'))->toBeTrue();
});

it('refuses a passkey assertion without user verification, from another origin, replayed, or cloned', function (): void {
    $user = makeUser('pk-assert@example.test');
    $this->actingAs($user);
    steppedUp($user, 'email');
    $authenticator = enrolPasskey($user);
    $service = app(PasskeyService::class);

    expect($service->verifyAssertion($authenticator->assert($service->assertionOptions(), userVerified: false)))->toBeNull()
        ->and($service->verifyAssertion($authenticator->assert($service->assertionOptions(), origin: 'https://kaimini.coredigify.com.evil.test')))->toBeNull();

    $response = $authenticator->assert($service->assertionOptions(), counter: 10);
    expect($service->verifyAssertion($response))->not->toBeNull()
        // challenge was single-use
        ->and($service->verifyAssertion($response))->toBeNull()
        // a cloned key (counter going back) is refused
        ->and($service->verifyAssertion($authenticator->assert($service->assertionOptions(), counter: 5)))->toBeNull()
        ->and($service->verifyAssertion($authenticator->assert($service->assertionOptions(), counter: 11)))->not->toBeNull();
});

it('throttles failed passkey logins per device bucket', function (): void {
    $component = Livewire::test(EmailLogin::class);

    for ($i = 0; $i < 10; $i++) {
        $component->call('passkeyAssert', '{}')->assertHasErrors('passkey');
    }

    $component->call('passkeyAssert', '{}')->assertHasErrors('passkey');
    expect($component->errors()->first('passkey'))->toContain(explode(':', __('auth.passkey.cooldown'))[0]);
    $this->assertGuest();
});

it('never logs a deleted account in with its passkey (erasure removes it)', function (): void {
    $user = makeUser('pk-erased@example.test');
    $this->actingAs($user);
    steppedUp($user, 'email');
    $authenticator = enrolPasskey($user);
    $service = app(PasskeyService::class);
    $options = $service->assertionOptions();

    app(AccountEraser::class)->erase($user->refresh());

    expect(Passkey::query()->count())->toBe(0)
        ->and($service->verifyAssertion($authenticator->assert($options)))->toBeNull();
});

// ---- step-up ----

it('confirms a step-up with one passkey tap and only with a passkey of the signed-in account', function (): void {
    $admin = platformAdmin('pk-stepup@example.test');
    $other = makeUser('pk-stepup-other@example.test');
    $this->actingAs($other);
    steppedUp($other, 'email');
    $foreign = enrolPasskey($other);

    $this->actingAs($admin);
    steppedUp($admin, 'email');
    $mine = enrolPasskey($admin);
    app(StepUpService::class)->forget();

    $this->get('/admin/platform')->assertRedirect(route('step-up'));

    $component = Livewire::test(StepUp::class);
    $options = returnedFrom($component, 'passkeyOptions');
    $component->call('passkeyAssert', $foreign->assert($options))->assertHasErrors('passkey');
    expect(app(StepUpService::class)->hasFreshMfa($admin))->toBeFalse();

    $options = returnedFrom($component, 'passkeyOptions');
    $component->call('passkeyAssert', $mine->assert($options))->assertHasNoErrors()->assertRedirect('/admin/platform');
    expect(app(StepUpService::class)->hasFreshStrongMfa($admin))->toBeTrue();
});

it('lets an administrator without any second factor create a passkey right on the step-up page', function (): void {
    $admin = platformAdmin('pk-first@example.test');
    $this->actingAs($admin)->withSession([StepUpService::LOGIN_KEY => ['user' => $admin->id, 'at' => now()->getTimestamp(), 'method' => 'email_code']]);
    $this->get('/admin/platform')->assertRedirect(route('step-up'));

    $component = Livewire::test(StepUp::class)->assertViewHas('mayEnrol', true)->set('trust', true);
    $options = returnedFrom($component, 'passkeyRegisterOptions');
    $component->call('passkeyRegister', (new FakeAuthenticator)->register($options))->assertHasNoErrors()->assertRedirect('/admin/platform');

    expect($admin->passkeys()->count())->toBe(1)
        ->and(app(StepUpService::class)->hasFreshMfa($admin))->toBeTrue()
        ->and($admin->trustedDevices()->count())->toBe(1);
});

it('offers a passkey once after an administrator logs in with the e-mail, and "Vēlāk" continues', function (): void {
    $admin = platformAdmin('pk-offer@example.test');
    $resident = makeUser('pk-offer-resident@example.test');
    $completer = app(LoginCompleter::class);

    $response = $completer->login(request(), $admin, false, 'email_code');
    expect($response->getTargetUrl())->toEndWith('/me/passkey');

    Livewire::test(PasskeyOffer::class)->call('later')->assertRedirect('/home');

    auth()->logout();
    expect($completer->login(request(), $resident, false, 'email_code')->getTargetUrl())->toEndWith('/home');
});

// ---- removal ----

it('removes a passkey only after a fresh passkey/TOTP of this session and signs the other devices out', function (): void {
    $user = makeUser('pk-remove@example.test');
    $this->actingAs($user);
    steppedUp($user, 'email');
    enrolPasskey($user);
    $passkey = $user->passkeys()->sole();
    $epoch = (int) $user->refresh()->auth_epoch;

    steppedUp($user, 'trusted');
    Livewire::test(Security::class)->call('removePasskey', $passkey->id)->assertRedirect(route('step-up', absolute: false));
    expect(Passkey::query()->count())->toBe(1);

    steppedUp($user, 'passkey');
    Livewire::test(Security::class)->call('removePasskey', $passkey->id)->assertRedirect(route('me.security'));

    expect(Passkey::query()->count())->toBe(0)
        ->and((int) $user->refresh()->auth_epoch)->toBe($epoch + 1)
        ->and(AuditEntry::query()->platformChain()->where('action', 'account.passkey_removed')->count())->toBe(1);
    Notification::assertSentTo($user, SecurityChangedNotice::class, fn (SecurityChangedNotice $n): bool => $n->kind === 'passkey_removed');
});

it('never lets one account remove another account\'s passkey', function (): void {
    $owner = makeUser('pk-owner@example.test');
    $this->actingAs($owner);
    steppedUp($owner, 'email');
    enrolPasskey($owner);
    $passkey = $owner->passkeys()->sole();

    $intruder = makeUser('pk-intruder@example.test');
    $this->actingAs($intruder);
    steppedUp($intruder, 'passkey');
    Livewire::test(Security::class)->call('removePasskey', $passkey->id);

    expect(Passkey::query()->whereKey($passkey->id)->exists())->toBeTrue();
});

// ---- recovery ----

it('resets every sign-in factor of an account that lost its devices (console only) and lets it enrol again', function (): void {
    $user = platformAdmin('pk-lost@example.test');
    $this->actingAs($user);
    steppedUp($user, 'email');
    enrolPasskey($user);
    $user->forceFill(['app_authentication_secret' => app(Google2FA::class)->generateSecretKey(32)])->save();
    TrustedDevice::query()->create([
        'user_id' => $user->id, 'token_hash' => hash('sha256', 'x'), 'auth_epoch' => (int) $user->auth_epoch,
        'label' => 'Chrome · Android', 'method' => 'passkey', 'expires_at' => now()->addDays(30),
    ]);
    $epoch = (int) $user->refresh()->auth_epoch;

    $this->artisan('majas:reset-mfa', ['email' => 'pk-lost@example.test', '--force' => true])->assertSuccessful();

    $user->refresh();
    expect($user->passkeys()->count())->toBe(0)
        ->and($user->hasTotp())->toBeFalse()
        ->and(TrustedDevice::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and((int) $user->auth_epoch)->toBe($epoch + 1)
        ->and(AuditEntry::query()->platformChain()->where('action', 'account.factors_reset')->count())->toBe(1);
    Notification::assertSentTo($user, SecurityChangedNotice::class, fn (SecurityChangedNotice $n): bool => $n->kind === 'factors_reset');

    // Unknown accounts are refused; nothing is printed about who exists beyond "no such account".
    $this->artisan('majas:reset-mfa', ['email' => 'nobody@example.test', '--force' => true])->assertFailed();
});

it('keeps the login buttons enabled while the passkey autofill request runs (loading state scoped to the form)', function (): void {
    // The login page asks for passkey options as soon as it loads (conditional UI). An unscoped wire:loading disabled
    // "Nosūtīt saiti" / "Ieiet" during that request, so a quick tap did nothing (found by the prodlike passkey run).
    $this->get('/login')->assertOk()
        ->assertSee('wire:loading.attr="disabled" wire:target="send"', false)
        ->assertSee('autocomplete="email webauthn"', false)
        ->assertSee('data-testid="passkey-button"', false);
    $this->get('/login/pin')->assertOk()->assertSee('wire:loading.attr="disabled" wire:target="login"', false);
});

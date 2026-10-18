<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Livewire\Auth\StepUp;
use App\Notifications\StepUpCode;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

it('redirects elevated pages to step-up and back after an e-mail code', function (): void {
    Notification::fake();
    $b = makeBuilding('su-a', 1, 1, open: true);
    $elder = makeUser('su-elder@example.test');
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);

    $this->actingAs($elder)->get('/b/su-a/help')->assertRedirect(route('step-up'));

    $component = Livewire::actingAs($elder)->test(StepUp::class)->call('sendCode')->assertSet('sent', true);
    $code = null;
    Notification::assertSentTo($elder, StepUpCode::class, function (StepUpCode $n) use (&$code): bool {
        $code = $n->code();

        return true;
    });

    // Back to the page that asked (markFresh used to overwrite the nested return URL → Home).
    $component->set('code', $code)->call('verify')->assertHasNoErrors()->assertRedirect('/b/su-a/help');
    expect(app(StepUpService::class)->hasFresh($elder))->toBeTrue()
        ->and(app(StepUpService::class)->hasFreshMfa($elder))->toBeFalse();
});

it('expires after 2 hours', function (): void {
    $user = makeUser('su-exp@example.test');
    $this->actingAs($user);
    app(StepUpService::class)->markFresh($user, 'email');

    $this->travel(121)->minutes();
    expect(app(StepUpService::class)->hasFresh($user))->toBeFalse();
});

it('accepts TOTP once per time step (replay refused)', function (): void {
    $google = app(Google2FA::class);
    $secret = $google->generateSecretKey(32);
    $user = makeUser('su-totp@example.test');
    $user->forceFill(['app_authentication_secret' => $secret])->save();
    $this->actingAs($user);

    $code = $google->getCurrentOtp($secret);
    expect(app(StepUpService::class)->verifyTotp($user, $code))->toBeTrue()
        ->and(app(StepUpService::class)->hasFreshMfa($user))->toBeTrue()
        ->and(app(StepUpService::class)->verifyTotp($user, $code))->toBeFalse();
});

it('rejects wrong e-mail codes and burns the code after 5 attempts', function (): void {
    Notification::fake();
    $user = makeUser('su-wrong@example.test');
    $this->actingAs($user);
    app(StepUpService::class)->sendEmailCode($user);
    $code = null;
    Notification::assertSentTo($user, StepUpCode::class, function (StepUpCode $n) use (&$code): bool {
        $code = $n->code();

        return true;
    });

    for ($i = 0; $i < 5; $i++) {
        expect(app(StepUpService::class)->verifyEmailCode($user, $code === '000000' ? '111111' : '000000'))->toBeFalse();
    }
    expect(app(StepUpService::class)->verifyEmailCode($user, $code))->toBeFalse();
});

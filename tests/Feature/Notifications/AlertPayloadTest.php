<?php

declare(strict_types=1);

use App\Auth\PinAuthService;
use App\Enums\BuildingRoleType;
use App\Models\User;
use App\Notifications\CoreGateChanged;
use App\Notifications\LimiterAlert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;

/*
 * Alert payloads carry counts only: building, limiter, window, count, threshold — never a handle, plate, bidx,
 * code, IP, user id or apartment (MVP-SPEC A.6).
 */
function serialiseNotification(object $notification, object $notifiable): string
{
    $mail = $notification->toMail($notifiable);
    $parts = $mail instanceof MailMessage ? array_merge([$mail->subject], $mail->introLines, $mail->outroLines) : [];

    return json_encode([$parts, method_exists($notification, 'toArray') ? $notification->toArray($notifiable) : []]);
}

it('sends count-only PIN limiter alerts', function (): void {
    Notification::fake();
    config(['majas.pin.global_failed_alert_per_hour' => 3]);
    $b = makeBuilding('alert-a', 1, 1);
    $admin = makeUser('alert-admin@example.test', ['platform_role' => 'platform_admin']);
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $b->forceFill(['settings' => $b->settings->with(['pin_limits' => ['building_hour' => 4]])])->save();

    $victim = User::query()->create(['locale' => 'lv']);
    $victim->forceFill(['login_handle' => '3-SECR'])->save();
    app(PinAuthService::class)->setPin($victim, '958372');
    member($victim, $b, 3);

    for ($i = 0; $i < 4; $i++) {
        app(PinAuthService::class)->attempt('3-SECR', '000001', "198.18.0.{$i}");
    }

    $payloads = [];
    Notification::assertSentTo($admin, LimiterAlert::class, function (LimiterAlert $n) use ($admin, &$payloads): bool {
        $payloads[] = serialiseNotification($n, $admin);

        return true;
    });

    expect($payloads)->not->toBeEmpty();
    foreach ($payloads as $payload) {
        expect($payload)->not->toContain('3-SECR')
            ->not->toContain('198.18.0')
            ->not->toContain('"user_id"')
            ->not->toContain((string) $victim->id.'"')
            ->not->toContain('apartment');
    }
});

it('keeps gate alerts free of personal data', function (): void {
    $n = new CoreGateChanged('Testa iela 1', false, ['dpa_ref', 'privacy_notice']);
    $payload = serialiseNotification($n, makeUser('x@example.test'));

    expect($payload)->not->toContain('@example.test')->toContain('Testa iela 1');
});

<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Enums\PrivacyNoticeKind;
use App\Models\PrivacyNoticeVersion;
use App\Notifications\Alerts;
use App\Services\Invites\InvalidInviteCode;
use App\Services\Invites\InviteCodeService;
use App\Services\Onboarding\JoinState;
use App\Support\Identifiers;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\HouseClosed;
use App\Tenancy\InviteResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step 2: "Ievadiet kodu no pastkastītes vēstules" (MVP-SPEC A.6 /join, AUTH-002).
 * The QR opens /join#c=CODE; Alpine copies the fragment into the field and clears it; the user taps
 * "Turpināt". The `invite` throttle (5/15 min per IP; 60/h per building after a resolve; the platform-wide
 * monitor is alert-only and counts well-formed codes only) runs BEFORE InviteResolver. The code is never logged and never kept after this request.
 */
#[Layout('layouts::guest')]
final class RedeemInvite extends Component
{
    public string $code = '';

    public ?string $error = null;

    public bool $cooldown = false;

    public bool $closed = false;

    public function submit(InviteCodeService $codes, InviteResolver $resolver): void
    {
        $this->error = null;
        $this->closed = false;

        if (! $this->passesThrottle()) {
            $this->cooldown = true;
            $this->code = '';

            return;
        }

        try {
            $codes->normalize($this->code);
        } catch (InvalidInviteCode $e) {
            $this->error = $e->reason === 'ambiguous' ? __('onboarding.code.ambiguous') : __('onboarding.code.invalid');

            return;
        }

        $this->monitorGlobal();

        try {
            $resolved = $resolver->resolve($this->code);
        } catch (HouseClosed) {
            $this->closed = true;
            $this->code = '';

            return;
        }

        $this->code = '';

        if ($resolved === null) {
            $this->error = __('onboarding.code.invalid');

            return;
        }

        // Per-building burst limit (building known only after a successful resolve).
        $buildingKey = 'invite-building:'.$resolved->buildingId;
        $limit = (int) config('majas.invites.throttle.building_per_hour', 60);
        if (RateLimiter::tooManyAttempts($buildingKey, $limit)) {
            $this->cooldown = true;

            return;
        }
        RateLimiter::hit($buildingKey, 3600);
        if (RateLimiter::attempts($buildingKey) === $limit) {
            app(Alerts::class)->building(CurrentBuilding::get(), 'invite-building', 'hour', $limit, $limit);
        }

        $platformNotice = PrivacyNoticeVersion::current(PrivacyNoticeKind::PlatformAccount);

        (new JoinState(invite: $resolved, platformNoticeVersion: $platformNotice?->version))->save(request());

        $this->redirectRoute('join.role');
    }

    private function passesThrottle(): bool
    {
        $ipKey = 'invite-ip:'.Identifiers::ipBucket((string) request()->ip());

        if (RateLimiter::tooManyAttempts($ipKey, (int) config('majas.invites.throttle.ip_per_15min', 5))) {
            return false;
        }

        RateLimiter::hit($ipKey, 15 * 60);

        return true;
    }

    /**
     * Platform-wide monitor, ALERT-ONLY (like pin-global): it never refuses a code, so a handful of IPs can no
     * longer close /join for every building. Only well-formed codes are counted.
     */
    private function monitorGlobal(): void
    {
        $key = 'invite-global:'.now()->format('YmdH');
        $threshold = (int) config('majas.invites.throttle.global_alert_per_hour', 500);
        RateLimiter::hit($key, 3600);

        if (RateLimiter::attempts($key) === $threshold) {
            app(Alerts::class)->platform('invite-global', 'hour', $threshold, $threshold);
        }
    }

    public function render(): View
    {
        return view('livewire.onboarding.redeem-invite')->title(__('onboarding.code.title'));
    }
}

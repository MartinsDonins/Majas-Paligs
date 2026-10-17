<?php

declare(strict_types=1);

namespace App\Livewire\Me;

use App\Audit\AuditLogger;
use App\Auth\StepUpService;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * Second factor (TOTP authenticator app) for elevated roles — required for platform_admin, optional step-up
 * method for elders/admins (PLANNING §4.5 item 4). The pending secret lives only in the server session until
 * confirmed with a valid code; then it is stored encrypted.
 */
#[Layout('layouts::guest')]
final class Security extends Component
{
    private const PENDING = 'mp.totp.pending';

    public string $code = '';

    public function start(Google2FA $google2fa): void
    {
        session()->put(self::PENDING, $google2fa->generateSecretKey(32));
    }

    public function confirm(Google2FA $google2fa, StepUpService $stepUp): void
    {
        $secret = (string) session(self::PENDING, '');
        $code = (string) preg_replace('/\D/', '', $this->code);
        $this->code = '';

        if ($secret === '' || $google2fa->verifyKey($secret, $code, 1) === false) {
            $this->addError('code', __('auth.step_up.invalid'));

            return;
        }

        $user = $this->user();
        $user->forceFill(['app_authentication_secret' => $secret])->save();
        session()->forget(self::PENDING);
        AuditLogger::record('account.totp_enabled', $user, [(int) $user->id], platform: true, actorRole: 'self');
        $stepUp->markFresh($user, 'totp');

        session()->flash('status', 'totp_enabled');
    }

    public function disable(StepUpService $stepUp): void
    {
        $user = $this->user();

        if (! $stepUp->hasFresh($user)) {
            session()->put('mp.step_up.intended', route('me.security'));
            $this->redirectRoute('step-up');

            return;
        }

        $user->forceFill(['app_authentication_secret' => null])->save();
        AuditLogger::record('account.totp_disabled', $user, [(int) $user->id], platform: true, actorRole: 'self');
        session()->flash('status', 'totp_disabled');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(Google2FA $google2fa): View
    {
        $user = $this->user();
        $pending = session(self::PENDING);
        $qr = null;

        if (is_string($pending) && $pending !== '') {
            $qr = $google2fa->getQRCodeInline(
                (string) config('app.name'),
                $user->email ?? ($user->login_handle ?? 'user'),
                $pending,
            );
        }

        return view('livewire.me.security', [
            'enabled' => $user->hasTotp(),
            'pending' => is_string($pending) ? trim(chunk_split($pending, 4, ' ')) : null,
            'qr' => $qr,
        ])->title(__('me.security.title'));
    }
}

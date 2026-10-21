<?php

declare(strict_types=1);

namespace App\Livewire\Me;

use App\Audit\AuditLogger;
use App\Auth\Passkeys\PasskeyService;
use App\Auth\SessionEpoch;
use App\Auth\StepUpService;
use App\Auth\TrustedDevices;
use App\Livewire\Concerns\RegistersPasskey;
use App\Models\Passkey;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\Alerts;
use App\Notifications\TotpChangedNotice;
use App\Support\SafeReturn;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * "Ieeja un drošība" (/me/security, ADR 0006) — for every account:
 * 1. Passkeys ("ieejas atslēgas": fingerprint / Face ID / screen lock) — add, list, remove. The primary way in for
 *    administrators: a passkey login is MFA, a passkey tap satisfies every step-up.
 * 2. Trusted devices ("Uzticēties šai ierīcei 30 dienas") — list, revoke one, revoke all; trust this one right after
 *    a real MFA.
 * 3. Authenticator app (TOTP) — the fallback second factor (required before 0.0.23, still accepted).
 *
 * Factor rules (review fix, drošība; extended to passkeys): a second factor can never be added or removed with the
 * first factor alone.
 * - Adding (passkey or TOTP): StepUpService::mayAddFactor — with an existing factor a fresh passkey/TOTP of THIS
 *   session; without one a fresh first-factor proof (e-mail step-up or a login < 15 min ago).
 * - Removing a passkey or disabling TOTP: a fresh passkey/TOTP of THIS session (a trusted device is not enough).
 * - TOTP enrolment is refused while a secret exists — replace = disable first.
 * - The confirm code of a FIRST TOTP enrolment counts as this session's TOTP step-up (StepUpService::markEnrolled).
 * - Every change: platform audit chain + e-mail to the account; TOTP changes and passkey removal also bump the
 *   session epoch (other sessions, remember cookies and trusted devices end); adding a passkey does not, so adding the
 *   second phone does not sign the first one out.
 */
#[Layout('layouts::guest')]
final class Security extends Component
{
    use RegistersPasskey;

    private const PENDING = 'mp.totp.pending';

    /** The page that sent the person here to enrol (the step-up banner), resumed after a successful enrolment. */
    public const AFTER_ENROL = 'mp.totp.after';

    public string $code = '';

    public function start(Google2FA $google2fa, StepUpService $stepUp): void
    {
        $user = $this->user();

        if (! $this->mayEnrol($user, $stepUp)) {
            return;
        }

        session()->put(self::PENDING, $google2fa->generateSecretKey(32));
    }

    public function confirm(Google2FA $google2fa, StepUpService $stepUp): void
    {
        $user = $this->user();
        $secret = (string) session(self::PENDING, '');
        $code = (string) preg_replace('/\D/', '', $this->code);
        $this->code = '';

        if (! $this->mayEnrol($user, $stepUp)) {
            session()->forget(self::PENDING);

            return;
        }

        if ($secret === '' || $google2fa->verifyKey($secret, $code, 1) === false) {
            $this->addError('code', __('auth.step_up.invalid'));

            return;
        }

        $user->forceFill(['app_authentication_secret' => $secret])->save();
        session()->forget(self::PENDING);
        AuditLogger::record('account.totp_enabled', $user, [(int) $user->id], platform: true, actorRole: 'self');
        $this->announce($user, enabled: true);
        $stepUp->markEnrolled($user, $secret, $code);

        session()->flash('status', 'totp_enabled');

        // Back to where the person was going (e.g. /manage, via the step-up banner) once the authenticator works.
        $next = SafeReturn::path(session()->pull(self::AFTER_ENROL));
        if ($next !== null) {
            $this->redirect($next);
        }
    }

    public function disable(StepUpService $stepUp): void
    {
        $user = $this->user();

        if (! $user->hasTotp()) {
            return;
        }

        if (! $stepUp->hasFreshStrongMfa($user)) {
            $this->askStepUp('strong');

            return;
        }

        $user->forceFill(['app_authentication_secret' => null])->save();
        AuditLogger::record('account.totp_disabled', $user, [(int) $user->id], platform: true, actorRole: 'self');
        $this->announce($user, enabled: false);
        // The TOTP step-up of this session belonged to the removed secret.
        $stepUp->forget();
        session()->flash('status', 'totp_disabled');
    }

    public function removePasskey(int $id, PasskeyService $passkeys, StepUpService $stepUp): void
    {
        $user = $this->user();
        $passkey = $user->passkeys()->whereKey($id)->first();

        if (! $passkey instanceof Passkey) {
            return;
        }

        if (! $stepUp->hasFreshStrongMfa($user)) {
            $this->askStepUp('strong');

            return;
        }

        $passkeys->remove($user, $passkey);
        session()->flash('status', 'passkey_removed');
        $this->redirectRoute('me.security');
    }

    public function trustThisDevice(StepUpService $stepUp, TrustedDevices $devices): void
    {
        $user = $this->user();

        if (! $stepUp->hasFreshStrongMfa($user)) {
            $this->askStepUp('strong');

            return;
        }

        // TrustedDevices::trust only accepts a passkey / TOTP from the last 10 minutes.
        if ($devices->trust($user, request(), (string) (session(StepUpService::SESSION_KEY)['method'] ?? 'totp')) === null) {
            $this->askStepUp('strong');

            return;
        }

        session()->flash('status', 'device_trusted');
        $this->redirectRoute('me.security');
    }

    public function revokeDevice(int $id, TrustedDevices $devices): void
    {
        $devices->revoke($this->user(), $id);
        session()->flash('status', 'device_revoked');
        $this->redirectRoute('me.security');
    }

    public function revokeAllDevices(TrustedDevices $devices): void
    {
        $devices->revokeAll($this->user());
        session()->flash('status', 'device_revoked');
        $this->redirectRoute('me.security');
    }

    protected function passkeyReturnTo(): string
    {
        return route('me.security', absolute: false);
    }

    protected function passkeyRegistered(Passkey $passkey, User $user): void
    {
        session()->flash('status', 'passkey_added');

        $next = SafeReturn::path(session()->pull(self::AFTER_ENROL));
        $this->redirect($next ?? route('me.security', absolute: false));
    }

    /**
     * TOTP enrolment only without an existing authenticator, and only with the proof StepUpService::mayAddFactor
     * asks for.
     */
    private function mayEnrol(User $user, StepUpService $stepUp): bool
    {
        if ($user->hasTotp()) {
            $this->addError('code', __('me.security.replace_refused'));

            return false;
        }

        if (! $stepUp->mayAddFactor($user)) {
            $this->askStepUp($stepUp->addFactorLevel($user));

            return false;
        }

        return true;
    }

    private function askStepUp(string $level): void
    {
        $this->redirect(app(StepUpService::class)->ask($level, route('me.security', absolute: false)));
    }

    private function announce(User $user, bool $enabled): void
    {
        SessionEpoch::bump($user);
        $user->notify(new TotpChangedNotice($enabled));

        if ($user->isPlatformAdmin()) {
            app(Alerts::class)->platform('totp-changed', 'day', 1, 1);
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(Google2FA $google2fa, StepUpService $stepUp, TrustedDevices $devices): View
    {
        $user = $this->user();
        $pending = $user->hasTotp() ? null : session(self::PENDING);
        $qr = null;

        if (is_string($pending) && $pending !== '') {
            $qr = $google2fa->getQRCodeInline(
                (string) config('app.name'),
                $user->email ?? ($user->login_handle ?? 'user'),
                $pending,
            );
        }

        $thisDevice = $devices->current($user, request());

        return view('livewire.me.security', [
            'enabled' => $user->hasTotp(),
            'passkeys' => $user->passkeys()->orderBy('id')->get(),
            'devices' => TrustedDevice::query()->validFor($user)->orderByDesc('id')->get(),
            'thisDeviceId' => $thisDevice?->id,
            'canTrust' => $thisDevice === null && $stepUp->hasFreshStrongMfa($user),
            'elevated' => $stepUp->requiresMfa($user),
            // Back to the panel that sent the person here (step-up banner), otherwise Home.
            'back' => SafeReturn::path(session(self::AFTER_ENROL)) ?? route('home.redirect'),
            'pending' => is_string($pending) ? trim(chunk_split($pending, 4, ' ')) : null,
            'qr' => $qr,
        ])->title(__('me.security.title'));
    }
}

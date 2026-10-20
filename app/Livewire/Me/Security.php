<?php

declare(strict_types=1);

namespace App\Livewire\Me;

use App\Audit\AuditLogger;
use App\Auth\SessionEpoch;
use App\Auth\StepUpService;
use App\Models\User;
use App\Notifications\Alerts;
use App\Notifications\TotpChangedNotice;
use App\Support\SafeReturn;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * Second factor (TOTP authenticator app) for elevated roles — required for platform_admin, building_admin and
 * parking_officer (StepUpService::requiresMfa), optional for elders (PLANNING §4.5 item 4). The pending secret lives
 * only in the server session until confirmed with a valid code; then it is stored encrypted.
 *
 * Review fix (drošība): the second factor can never be replaced with the first factor alone.
 * - Enrolment (start / confirm) is refused while an authenticator exists — replace = disable first.
 * - A FIRST enrolment needs a fresh step-up (e-mail code); disabling needs a fresh TOTP step-up.
 * - The confirm code of a FIRST enrolment proves possession of the new authenticator, so it counts as this
 *   session's TOTP step-up (StepUpService::markEnrolled, time step burnt for replays): one code per login instead of
 *   a second one right after enrolling (QA 2026-10-20). It grants nothing a holder of the new secret could not get
 *   with the next code anyway; the guards above (no replacement, first factor + step-up before enrolment) stay.
 * - Every enable / disable: audit (platform chain), e-mail notice to the account, session epoch bump (other
 *   sessions end) and, for platform_admin, a count-only platform alert.
 */
#[Layout('layouts::guest')]
final class Security extends Component
{
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

        if (! $stepUp->hasFreshMfa($user)) {
            $this->askStepUp('mfa');

            return;
        }

        $user->forceFill(['app_authentication_secret' => null])->save();
        AuditLogger::record('account.totp_disabled', $user, [(int) $user->id], platform: true, actorRole: 'self');
        $this->announce($user, enabled: false);
        // The TOTP step-up of this session belonged to the removed secret.
        $stepUp->forget();
        session()->flash('status', 'totp_disabled');
    }

    /**
     * Enrolment only without an existing authenticator, and only after a fresh step-up.
     */
    private function mayEnrol(User $user, StepUpService $stepUp): bool
    {
        if ($user->hasTotp()) {
            $this->addError('code', __('me.security.replace_refused'));

            return false;
        }

        if (! $stepUp->hasFresh($user)) {
            $this->askStepUp('any');

            return false;
        }

        return true;
    }

    private function askStepUp(string $level): void
    {
        session()->put(StepUpService::INTENDED_KEY, route('me.security', absolute: false));
        session()->put(StepUpService::LEVEL_KEY, $level);
        $this->redirectRoute('step-up');
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

    public function render(Google2FA $google2fa): View
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

        return view('livewire.me.security', [
            'enabled' => $user->hasTotp(),
            // Back to the panel that sent the person here (step-up banner), otherwise Home.
            'back' => SafeReturn::path(session(self::AFTER_ENROL)) ?? route('home.redirect'),
            'pending' => is_string($pending) ? trim(chunk_split($pending, 4, ' ')) : null,
            'qr' => $qr,
        ])->title(__('me.security.title'));
    }
}

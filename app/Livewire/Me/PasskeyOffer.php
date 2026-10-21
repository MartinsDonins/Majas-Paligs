<?php

declare(strict_types=1);

namespace App\Livewire\Me;

use App\Auth\StepUpService;
use App\Auth\TrustedDevices;
use App\Livewire\Concerns\RegistersPasskey;
use App\Models\Passkey;
use App\Models\User;
use App\Support\SafeReturn;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Turpmāk ieejiet ar pirkstu / Face ID" (/me/passkey, ADR 0006) — shown once after an e-mail or PIN login to an
 * administrator (StepUpService::requiresMfa) who has no passkey yet. One tap creates the passkey; it also counts as
 * this session's MFA, so the admin goes straight on without an authenticator code. An account that already has an
 * authenticator confirms one code here first (adding a factor needs the existing one). "Vēlāk" simply continues.
 * A browser without passkey support (JS) shows the explanation and the "Turpināt" button only.
 */
#[Layout('layouts::guest')]
final class PasskeyOffer extends Component
{
    use RegistersPasskey;

    /** Where the login was going (set by LoginCompleter). */
    public const NEXT = 'mp.passkey.offer_next';

    public string $code = '';

    public bool $trust = false;

    public function mount(): void
    {
        if ($this->user()->hasPasskeys()) {
            $this->redirect($this->next());
        }
    }

    /** Authenticator code before adding a passkey to an account that already has TOTP. */
    public function verify(StepUpService $stepUp): void
    {
        $user = $this->user();
        $code = $this->code;
        $this->code = '';

        if (! $user->hasTotp() || ! $stepUp->verifyTotp($user, $code)) {
            $this->addError('code', $user->hasTotp() && $stepUp->totpWasReplayed($user, $code)
                ? __('auth.step_up.replayed')
                : __('auth.step_up.invalid'));
        }
    }

    public function later(): void
    {
        $this->redirect($this->next(pull: true));
    }

    protected function passkeyReturnTo(): string
    {
        return route('me.passkey', absolute: false);
    }

    protected function passkeyRegistered(Passkey $passkey, User $user): void
    {
        if ($this->trust) {
            app(TrustedDevices::class)->trust($user, request(), 'passkey');
        }

        session()->flash('status', 'passkey_added');
        $this->redirect($this->next(pull: true));
    }

    private function next(bool $pull = false): string
    {
        $next = $pull ? session()->pull(self::NEXT) : session(self::NEXT);

        return SafeReturn::path($next) ?? route('home.redirect', absolute: false);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(StepUpService $stepUp): View
    {
        $user = $this->user();

        return view('livewire.me.passkey-offer', [
            'needsTotp' => $user->hasTotp() && ! $stepUp->hasFreshStrongMfa($user),
        ])->title(__('me.passkey_offer.title'));
    }
}

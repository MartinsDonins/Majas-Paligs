<?php

declare(strict_types=1);

namespace App\Livewire\Elder;

use App\Auth\WeakPinRule;
use App\Enums\MembershipRole;
use App\Models\User;
use App\Services\Invites\InvalidInviteCode;
use App\Services\Invites\InviteCodeService;
use App\Services\Onboarding\AssistedOnboardingService;
use App\Support\Identifiers;
use App\Tenancy\Exceptions\BuildingContextAlreadySet;
use App\Tenancy\Exceptions\HouseClosed;
use App\Tenancy\InviteResolver;
use App\Tenancy\ResolvedInvite;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

/**
 * Elder hands the phone to the neighbour: code from the neighbour's letter → "Tas esmu es" (18+, notices,
 * role) → neighbour sets a PIN on this phone → handle shown once for the "Mans PIN" card.
 * The resolved invite lives in the server session, never in a public property.
 */
final class AssistedOnboarding extends Component
{
    private const STATE = 'mp.elder.assist';

    public string $code = '';

    public string $role = '';

    public bool $itsMe = false;

    public string $pin = '';

    public string $pinConfirm = '';

    public ?string $createdHandle = null;

    public function lookup(InviteCodeService $codes, InviteResolver $resolver): void
    {
        $key = 'elder-assist:'.Identifiers::ipBucket((string) request()->ip()).':'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('code', __('onboarding.cooldown.body', ['minutes' => 15]));

            return;
        }
        RateLimiter::hit($key, 15 * 60);

        $typed = $this->code;
        $this->code = '';

        try {
            $codes->normalize($typed);
            $resolved = $resolver->resolve($typed);
        } catch (InvalidInviteCode|BuildingContextAlreadySet) {
            $resolved = null;
        } catch (HouseClosed) {
            $this->addError('code', __('onboarding.closed.title'));

            return;
        }

        if ($resolved === null) {
            $this->addError('code', __('onboarding.code.invalid'));

            return;
        }

        session()->put(self::STATE, $resolved->toArray());
    }

    public function create(AssistedOnboardingService $service): void
    {
        $state = session(self::STATE);
        if (! is_array($state)) {
            return;
        }
        $invite = ResolvedInvite::fromArray($state);

        try {
            $this->validate([
                'itsMe' => ['accepted'],
                'role' => ['required', 'in:owner,tenant,family'],
                'pin' => ['required', 'string', new WeakPinRule($invite->apartmentNumber)],
                'pinConfirm' => ['required', 'same:pin'],
            ], [
                'itsMe.accepted' => __('elder.assisted.its_me_required'),
                'role.required' => __('onboarding.role.required'),
                'pinConfirm.same' => __('auth.pin.mismatch'),
                'pinConfirm.required' => __('auth.pin.mismatch'),
            ]);
        } catch (ValidationException $e) {
            $this->pin = $this->pinConfirm = '';

            throw $e;
        }

        /** @var User $elder */
        $elder = auth()->user();

        try {
            $user = $service->create($elder, $invite, MembershipRole::from($this->role), $this->pin);
        } catch (Throwable $e) {
            report($e);
            $this->pin = $this->pinConfirm = '';
            $this->addError('code', __('elder.assisted.failed'));

            return;
        }

        $this->pin = $this->pinConfirm = '';
        session()->forget(self::STATE);
        $this->createdHandle = $user->login_handle;
    }

    public function restart(): void
    {
        session()->forget(self::STATE);
        $this->reset();
    }

    public function render(): View
    {
        $state = session(self::STATE);

        return view('livewire.elder.assisted-onboarding', [
            'resolved' => is_array($state) ? ResolvedInvite::fromArray($state) : null,
        ]);
    }
}

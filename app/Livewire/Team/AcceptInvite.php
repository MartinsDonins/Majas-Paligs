<?php

declare(strict_types=1);

namespace App\Livewire\Team;

use App\Auth\LoginCompleter;
use App\Enums\BuildingRoleType;
use App\Livewire\Me\PasskeyOffer;
use App\Models\RoleInvitation;
use App\Models\Section;
use App\Models\User;
use App\Services\Team\TeamInvitationRefused;
use App\Services\Team\TeamInvitationService;
use App\Support\Identifiers;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * GET /team-invite/{building:slug}#t=TOKEN — accepting a team invitation (ROUND2-SPEC §2.2 item 2, AC-R2-04/05).
 * Runs in the `tenant` middleware context of the bound slug: the token is looked up only inside THAT building, so another
 * building's slug simply finds nothing (no new scope lift, no new context setter).
 *
 * The token arrives in the URL fragment; Alpine (`fragmentAction`) clears the address bar and passes it as an ACTION
 * ARGUMENT to open() — it is never a public property. After a valid open the page remembers the invitation id + token
 * hash in the (encrypted, server-side) session, so the e-mail code, the account creation and the login round-trip can
 * follow; it is cleared on acceptance. Every invalid / expired / revoked / resent / used / foreign token gets the SAME
 * neutral text and counts toward 10 tries per 15 min per device.
 *
 * States: loading → invalid | guest → code → (existing account: login) | confirm_new → ready → (accept) redirect;
 * a signed-in person with another e-mail sees wrong_email and can never accept.
 */
#[Layout('layouts::guest')]
final class AcceptInvite extends Component
{
    public const SESSION_KEY = 'mp.team_invite';

    private const TRIES = 10;

    #[Locked]
    public string $state = 'loading';

    #[Locked]
    public string $roleLabel = '';

    #[Locked]
    public string $sections = '';

    #[Locked]
    public string $emailHint = '';

    #[Locked]
    public bool $cooldown = false;

    public string $code = '';

    public bool $adult = false;

    public bool $notice = false;

    public bool $remember = true;

    public ?string $error = null;

    public function mount(TeamInvitationService $service): void
    {
        $invitation = $this->remembered($service);

        if ($invitation !== null) {
            $this->show($invitation);
        }
    }

    /**
     * Called once by Alpine with the fragment token ('' when the page was opened without one).
     */
    public function open(#[\SensitiveParameter] string $token = ''): void
    {
        $service = app(TeamInvitationService::class);

        if ($token === '') {
            $invitation = $this->remembered($service);
            if ($invitation === null) {
                $this->state = 'invalid';
            }

            return;
        }

        if ($this->throttled()) {
            return;
        }

        $invitation = $service->resolve($token);

        if ($invitation === null) {
            $this->hit();
            session()->forget(self::SESSION_KEY);
            $this->state = 'invalid';

            return;
        }

        session()->put(self::SESSION_KEY, [
            'building' => CurrentBuilding::id(),
            'id' => $invitation->id,
            'hash' => bin2hex((string) $invitation->token_hash),
            'verified' => false,
        ]);

        $this->show($invitation);
    }

    /**
     * "Apstiprināt ar e-pastu": a 6-digit code to the invited address.
     */
    public function sendCode(TeamInvitationService $service): void
    {
        $invitation = $this->current($service);
        if ($invitation === null || auth()->check()) {
            return;
        }

        $key = 'team-code-mail:'.$invitation->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->error = __('auth.email.cooldown', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]);

            return;
        }
        RateLimiter::hit($key, 15 * 60);

        $this->error = $service->sendCode($invitation, request()->ip()) ? null : __('team.accept.code_locked');
        $this->state = 'code';
    }

    public function verify(TeamInvitationService $service): void
    {
        $invitation = $this->current($service);
        $code = $this->code;
        $this->code = '';

        if ($invitation === null || auth()->check() || $this->throttled()) {
            return;
        }

        if (! $service->verifyCode($invitation, $code)) {
            $this->hit();
            $this->error = __('auth.email.code_invalid');

            return;
        }

        $this->error = null;
        session()->put(self::SESSION_KEY.'.verified', true);

        $existing = $service->existingAccount($invitation);
        if ($existing !== null) {
            $this->loginAs($existing);

            return;
        }

        $this->state = 'confirm_new';
    }

    /**
     * New account (only after the code): 18+ and the platform account notice, then signed in and back here.
     */
    public function createAccount(TeamInvitationService $service): void
    {
        $invitation = $this->current($service);
        if ($invitation === null || auth()->check() || session(self::SESSION_KEY.'.verified') !== true) {
            return;
        }

        $this->validate(['adult' => ['accepted'], 'notice' => ['accepted']], [
            'adult.accepted' => __('onboarding.role.adult_required'),
            'notice.accepted' => __('team.accept.notice_required'),
        ]);

        $this->loginAs($service->createAccount($invitation));
    }

    public function accept(TeamInvitationService $service): void
    {
        $invitation = $this->current($service);
        /** @var User|null $user */
        $user = auth()->user();

        if ($invitation === null || $user === null) {
            return;
        }

        try {
            $service->accept($user, $invitation);
        } catch (TeamInvitationRefused $e) {
            if ($e->reason === 'wrong_email') {
                $this->state = 'wrong_email';

                return;
            }
            session()->forget(self::SESSION_KEY);
            $this->state = 'invalid';

            return;
        }

        session()->forget(self::SESSION_KEY);
        $admin = route('admin.entry', absolute: false);

        // Elevated roles: offer a passkey first (ADR 0006) — one fingerprint instead of codes next time.
        $elevated = in_array($invitation->role, [BuildingRoleType::BuildingAdmin, BuildingRoleType::SectionElder, BuildingRoleType::ParkingOfficer], true);
        if ($elevated && ! $user->hasPasskeys()) {
            session()->put(PasskeyOffer::NEXT, $admin);
            $this->redirectRoute('me.passkey');

            return;
        }

        $this->redirect($admin);
    }

    public function render(): View
    {
        return view('livewire.team.accept-invite', [
            'building' => CurrentBuilding::get(),
        ])->title(__('team.accept.title'));
    }

    private function show(RoleInvitation $invitation): void
    {
        $this->roleLabel = $invitation->role->label();
        $this->emailHint = $invitation->email_hint;
        $this->sections = Section::query()->whereIn('id', $invitation->section_ids)->orderBy('number')->pluck('number')
            ->map(fn ($n): string => __('admin.section.short', ['n' => $n]))->implode(', ');

        /** @var User|null $user */
        $user = auth()->user();
        $service = app(TeamInvitationService::class);

        $this->state = match (true) {
            $user !== null && ! $service->emailMatches($invitation, $user) => 'wrong_email',
            $user !== null => 'ready',
            session(self::SESSION_KEY.'.verified') === true => 'confirm_new',
            default => 'guest',
        };
    }

    /**
     * The invitation this session opened, if it is still usable and belongs to this building.
     */
    private function remembered(TeamInvitationService $service): ?RoleInvitation
    {
        $saved = session(self::SESSION_KEY);

        if (! is_array($saved) || (int) ($saved['building'] ?? 0) !== CurrentBuilding::id()) {
            return null;
        }

        $hash = hex2bin((string) ($saved['hash'] ?? ''));

        return $hash === false ? null : $service->reload((string) ($saved['id'] ?? ''), $hash);
    }

    private function current(TeamInvitationService $service): ?RoleInvitation
    {
        $invitation = $this->remembered($service);

        if ($invitation === null) {
            session()->forget(self::SESSION_KEY);
            $this->state = 'invalid';
        }

        return $invitation;
    }

    private function loginAs(User $user): void
    {
        // Back to this page after the login (the session — and the remembered invitation — survives the regenerate).
        session()->put('url.intended', route('team.accept', ['building' => CurrentBuilding::get()->slug], absolute: false));
        $response = app(LoginCompleter::class)->login(request(), $user, $this->remember, 'email_code');

        $this->redirect($response->getTargetUrl());
    }

    private function throttleKey(): string
    {
        return 'team-invite:'.Identifiers::ipBucket((string) request()->ip());
    }

    private function throttled(): bool
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), self::TRIES)) {
            $this->cooldown = true;
            $this->state = 'invalid';

            return true;
        }

        return false;
    }

    private function hit(): void
    {
        RateLimiter::hit($this->throttleKey(), 15 * 60);
    }
}

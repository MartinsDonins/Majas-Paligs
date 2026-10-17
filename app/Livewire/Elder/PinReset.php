<?php

declare(strict_types=1);

namespace App\Livewire\Elder;

use App\Audit\AuditLogger;
use App\Auth\PinAuthService;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Notifications\PinResetNotice;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Elder PIN reset (MVP-SPEC A.6a): only the elder of the user's section (or building_admin), after step-up,
 * cap 5/day per elder. Shows a one-time temporary code ONCE ("Iedodiet šo kodu kaimiņam personīgi — derīgs
 * 24 h"); audit `pin.reset` with the user as subject; the user and the apartment's other approved members are
 * notified. Residents are listed by apartment + role only.
 */
final class PinReset extends Component
{
    public ?string $tempCode = null;

    public ?int $resetApartment = null;

    public function issue(int $membershipId, PinAuthService $pins): void
    {
        $building = CurrentBuilding::get();
        /** @var User $elder */
        $elder = auth()->user();

        $membership = ApartmentMembership::query()->approved()->findOrFail($membershipId);
        /** @var Apartment $apartment */
        $apartment = Apartment::query()->whereKey($membership->apartment_id)->firstOrFail();

        $allowed = $elder->isBuildingAdmin($building) || in_array($apartment->section_id, $elder->elderSectionIds($building), true);
        abort_unless($allowed && $membership->user_id !== $elder->id, 403);

        $capKey = 'pin-reset-elder:'.$elder->id;
        if (RateLimiter::tooManyAttempts($capKey, (int) config('majas.pin.elder_resets_per_day', 5))) {
            $this->addError('reset', __('elder.pin.cap'));

            return;
        }

        /** @var User $user */
        $user = User::query()->whereKey($membership->user_id)->whereNull('anonymised_at')->firstOrFail();
        abort_if($user->login_handle === null, 403);

        RateLimiter::hit($capKey, 86400);
        $this->tempCode = $pins->issueTempCode($user);
        $this->resetApartment = $apartment->number;

        AuditLogger::record('pin.reset', $user, [(int) $user->id], reasonCode: 'elder_reset', actorRole: $elder->isBuildingAdmin($building) ? 'building_admin' : 'section_elder', actorUserId: (int) $elder->id);

        $others = User::query()->whereIn('id', ApartmentMembership::query()->approved()
            ->where('apartment_id', $apartment->id)->where('user_id', '!=', $user->id)->pluck('user_id'))
            ->whereNull('anonymised_at')->get();
        Notification::send($others->push($user), new PinResetNotice($apartment->number));
    }

    public function done(): void
    {
        $this->tempCode = null;
        $this->resetApartment = null;
    }

    public function render(): View
    {
        $building = CurrentBuilding::get();
        /** @var User $elder */
        $elder = auth()->user();
        $isAdmin = $elder->isBuildingAdmin($building);
        $sectionIds = $elder->elderSectionIds($building);

        $rows = collect();
        if ($this->tempCode === null) {
            $memberships = ApartmentMembership::query()->approved()
                ->when(! $isAdmin, fn ($q) => $q->whereIn('apartment_id', Apartment::query()->whereIn('section_id', $sectionIds)->select('id')))
                ->where('user_id', '!=', $elder->id)
                ->with('apartment')
                ->get();

            $pinUsers = User::query()->whereIn('id', $memberships->pluck('user_id'))->whereNotNull('login_handle')->whereNull('anonymised_at')
                ->get(['id', 'pin_locked_until'])->keyBy('id');

            $rows = $memberships->filter(fn (ApartmentMembership $m) => $pinUsers->has($m->user_id))
                ->sortBy(fn (ApartmentMembership $m) => $m->apartment->number)
                ->map(fn (ApartmentMembership $m) => [
                    'id' => $m->id,
                    'apartment' => $m->apartment->number,
                    'role' => $m->role->label(),
                    'locked' => $pinUsers->get($m->user_id)?->pin_locked_until?->isFuture() ?? false,
                ])->values();
        }

        return view('livewire.elder.pin-reset', ['rows' => $rows]);
    }
}

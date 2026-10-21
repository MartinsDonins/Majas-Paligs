<?php

declare(strict_types=1);

namespace App\Livewire\Me;

use App\Audit\AuditLogger;
use App\Auth\SessionEpoch;
use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Enums\TextSize;
use App\Enums\Theme;
use App\Models\Apartment as ApartmentModel;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Mans dzīvoklis / Моя квартира" (UI-004): my apartment, its members (role + status only, never names),
 * remembered devices ("Aizmirst visus telefonus"), language, text size, theme, privacy, log out, leave.
 */
#[Layout('layouts::app')]
final class Apartment extends Component
{
    public bool $confirmLeave = false;

    public function setTextSize(string $size): void
    {
        $value = TextSize::tryFrom($size) ?? TextSize::Normal;
        $this->user()->forceFill(['text_size' => $value->value])->save();
        $this->redirectRoute('building.me', ['building' => CurrentBuilding::get()->slug]);
    }

    public function setTheme(string $theme): void
    {
        $value = Theme::tryFrom($theme) ?? Theme::System;
        $this->user()->forceFill(['theme' => $value->value])->save();
        $this->redirectRoute('building.me', ['building' => CurrentBuilding::get()->slug]);
    }

    public function forgetDevices(): void
    {
        $user = $this->user();
        // Session epoch bump: every "remember this phone" cookie AND every other live session ends at its next
        // request; this device stays logged in.
        SessionEpoch::bump($user);
        AuditLogger::record('account.devices_forgotten', $user, [(int) $user->id], platform: true, actorRole: 'self');
        session()->flash('status', 'devices_forgotten');
        $this->redirectRoute('building.me', ['building' => CurrentBuilding::get()->slug]);
    }

    public function askLeave(): void
    {
        $this->confirmLeave = true;
    }

    public function cancelLeave(): void
    {
        $this->confirmLeave = false;
    }

    public function leave(): void
    {
        $building = CurrentBuilding::get();
        $user = $this->user();
        $membership = $user->membershipFor($building);

        if ($membership === null) {
            return;
        }

        $this->authorize('end', $membership);

        $membership->forceFill(['status' => MembershipStatus::Ended, 'ended_at' => now()])->save();
        AuditLogger::record('membership.end', $membership, [(int) $user->id], reasonCode: 'self', actorRole: 'resident');

        $this->redirectRoute('home.redirect');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        $building = CurrentBuilding::get();
        $user = $this->user();
        $membership = $user->membershipFor($building);
        $apartment = $membership !== null ? ApartmentModel::query()->with('section')->whereKey($membership->apartment_id)->first() : null;

        $members = $apartment !== null
            ? ApartmentMembership::query()->active()->where('apartment_id', $apartment->id)->orderBy('id')->get(['id', 'user_id', 'role', 'status'])
            : collect();

        $current = $user->rolesIn($building);
        $roles = $current->map(fn ($r) => [
            'role' => $r->role,
            'section' => $r->section_id,
            'expires' => $r->expires_at,
        ]);

        // Elder / admin tools are reachable from "Vairāk" too (review fix): the Home tools card was the only way in.
        $isAdmin = $current->contains(fn ($r) => $r->role === BuildingRoleType::BuildingAdmin);
        $isElder = $current->contains(fn ($r) => $r->role === BuildingRoleType::SectionElder);
        // "Administrēšana" (→ /admin) for anyone with a manage role in ANY house, or a platform_admin (ROUND2-SPEC §1.1).
        $canManage = $user->isPlatformAdmin() || $user->canAccessPanel(Filament::getPanel('manage'));
        $pendingForMe = ($isAdmin || $isElder)
            ? ApartmentMembership::query()->pending()
                ->when(! $isAdmin, fn ($q) => $q->whereIn('apartment_id', ApartmentModel::query()->whereIn('section_id', $user->elderSectionIds($building))->select('id')))
                ->count()
            : 0;

        return view('livewire.me.apartment', [
            'building' => $building,
            'user' => $user,
            'membership' => $membership,
            'apartment' => $apartment,
            'members' => $members,
            'roles' => $roles,
            'elevated' => $current->contains(fn ($r) => in_array($r->role, [BuildingRoleType::BuildingAdmin, BuildingRoleType::SectionElder, BuildingRoleType::ParkingOfficer], true)),
            'isHelper' => $isAdmin || $isElder,
            'canManage' => $canManage,
            'pendingForMe' => $pendingForMe,
        ])->title(__('me.title'));
    }
}

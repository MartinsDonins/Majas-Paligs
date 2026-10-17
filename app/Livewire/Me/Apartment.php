<?php

declare(strict_types=1);

namespace App\Livewire\Me;

use App\Audit\AuditLogger;
use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Enums\TextSize;
use App\Enums\Theme;
use App\Models\Apartment as ApartmentModel;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
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
        // Rotating the remember token invalidates every "remember this phone" cookie (other open sessions end at
        // their normal session lifetime); this device stays logged in.
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        AuditLogger::record('account.devices_forgotten', $user, [(int) $user->id], platform: true, actorRole: 'self');
        session()->flash('status', 'devices_forgotten');
        $this->redirectRoute('building.me', ['building' => CurrentBuilding::get()->slug]);
    }

    public function askLeave(): void
    {
        $this->confirmLeave = true;
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

        $roles = $user->rolesIn($building)->map(fn ($r) => [
            'role' => $r->role,
            'section' => $r->section_id,
            'expires' => $r->expires_at,
        ]);

        return view('livewire.me.apartment', [
            'building' => $building,
            'user' => $user,
            'membership' => $membership,
            'apartment' => $apartment,
            'members' => $members,
            'roles' => $roles,
            'elevated' => $user->rolesIn($building)->contains(fn ($r) => in_array($r->role, [BuildingRoleType::BuildingAdmin, BuildingRoleType::SectionElder, BuildingRoleType::ParkingOfficer], true)),
        ])->title(__('me.title'));
    }
}

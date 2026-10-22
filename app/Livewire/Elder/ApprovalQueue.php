<?php

declare(strict_types=1);

namespace App\Livewire\Elder;

use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Services\Memberships\MembershipService;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Approval queue: pending memberships of the elder's own section(s) (building_admin: all). ≤3 taps:
 * open panel → "Apstiprināt" → done. Each action is authorised by the policy (never self-approve).
 */
final class ApprovalQueue extends Component
{
    public function approve(int $membershipId, MembershipService $service): void
    {
        $membership = ApartmentMembership::query()->findOrFail($membershipId);
        $service->approve($this->user(), $membership);
        session()->flash('elder.status', 'approved');
    }

    public function reject(int $membershipId, MembershipService $service): void
    {
        $membership = ApartmentMembership::query()->findOrFail($membershipId);
        $service->reject($this->user(), $membership);
        session()->flash('elder.status', 'rejected');
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
        $isAdmin = $user->isBuildingAdmin($building);
        $sectionIds = $user->elderSectionIds($building);

        $pending = ApartmentMembership::query()->pending()
            ->when(! $isAdmin, fn ($q) => $q->whereIn('apartment_id', Apartment::query()->whereIn('section_id', $sectionIds)->select('id')))
            ->with('apartment.section')
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        // FIRSTRUN-001: an administrator's own request cannot be approved by himself — point him to whoever can
        // (an elder of that stairwell, invited on the Komanda page) instead of a dead end.
        return view('livewire.elder.approval-queue', [
            'pending' => $pending,
            'me' => $user->id,
            'teamUrl' => $isAdmin ? url('/admin/b/'.$building->slug.'/team') : null,
        ]);
    }
}

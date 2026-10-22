<?php

declare(strict_types=1);

namespace App\Livewire\Me;

use App\Auth\StepUpService;
use App\Enums\MembershipRole;
use App\Models\User;
use App\Platform\PlatformHouses;
use App\Services\Memberships\SelfLinkRefused;
use App\Services\Memberships\SelfLinkService;
use App\Tenancy\CurrentBuilding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "Piesaistīt mani dzīvoklim" on /b/{slug}/me (FIRSTRUN-001) for an administrator of this house without an apartment
 * here: pick the apartment and the role — no letter needed. A building_admin's request stays PENDING (never
 * self-approve; another admin or the stairwell elder approves it). A platform_admin's is approved at once under a
 * strong step-up (PlatformHouses::linkSelf, documented exception). Rendered by Me\Apartment only for those two.
 */
final class LinkMyself extends Component
{
    public string $apartmentNumber = '';

    public string $role = 'owner';

    public ?string $refused = null;

    public function link(StepUpService $stepUp, SelfLinkService $service, PlatformHouses $houses): void
    {
        $this->validate([
            'apartmentNumber' => ['required', 'integer', 'min:1', 'max:99999'],
            'role' => ['required', 'in:owner,tenant,family'],
        ]);

        /** @var User $user */
        $user = auth()->user();
        $building = CurrentBuilding::get();
        $platform = $user->isPlatformAdmin();

        if (! $platform && ! $user->isBuildingAdmin($building)) {
            abort(403);
        }

        if ($platform) {
            if (! $stepUp->demandStrong($user, $this)) {
                return;
            }
        } elseif (! $stepUp->hasFreshMfa($user)) {
            $this->redirect($stepUp->ask('mfa', session()->previousUrl()));

            return;
        }

        try {
            $platform
                ? $houses->linkSelf($user, $building, (int) $this->apartmentNumber, MembershipRole::from($this->role))
                : $service->link($user, (int) $this->apartmentNumber, MembershipRole::from($this->role));
        } catch (SelfLinkRefused $e) {
            $this->refused = $e->reason;

            return;
        } catch (AuthorizationException) {
            $this->refused = 'not_allowed';

            return;
        }

        session()->flash('status', $platform ? 'self_linked' : 'self_requested');
        $this->redirectRoute('building.me', ['building' => $building->slug]);
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('livewire.me.link-myself', ['platform' => $user->isPlatformAdmin()]);
    }
}

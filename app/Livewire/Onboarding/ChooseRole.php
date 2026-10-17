<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Enums\MembershipRole;
use App\Enums\PrivacyNoticeKind;
use App\Models\BuildingPrivacyNotice;
use App\Models\PrivacyNoticeVersion;
use App\Models\User;
use App\Services\Buildings\BuildingGrid;
use App\Services\Onboarding\JoinCompleter;
use App\Services\Onboarding\JoinState;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\HouseClosed;
use App\Tenancy\InviteResolver;
use App\Tenancy\ResolvedInvite;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step 3: confirm apartment (+ 602 grid highlight), "Kas apstrādā datus" (Art. 13: building notice with the
 * house controller + CoreDigify platform account notice) BEFORE the role choice, 18+ confirmation, role.
 * Every request re-enters the invite's building through InviteResolver::resume (gate re-checked).
 */
#[Layout('layouts::guest')]
final class ChooseRole extends Component
{
    public string $role = '';

    public bool $adult = false;

    public bool $noticesAccepted = false;

    public bool $closed = false;

    public function mount(InviteResolver $resolver): void
    {
        if ($this->enter($resolver) === null) {
            $this->closed || $this->redirectRoute('join');
        }
    }

    public function choose(InviteResolver $resolver, JoinCompleter $completer): void
    {
        $state = JoinState::get(request());
        $resolved = $this->enter($resolver);

        if ($state === null || $resolved === null) {
            $this->closed || $this->redirectRoute('join');

            return;
        }

        $this->validate([
            'role' => ['required', 'in:owner,tenant,family'],
            'adult' => ['accepted'],
            'noticesAccepted' => ['accepted'],
        ], [
            'role.required' => __('onboarding.role.required'),
            'role.in' => __('onboarding.role.required'),
            'adult.accepted' => __('onboarding.role.adult_required'),
            'noticesAccepted.accepted' => __('onboarding.role.notices_required'),
        ]);

        $state->role = MembershipRole::from($this->role);
        $state->adultConfirmed = true;
        $state->save(request());

        $user = auth()->user();

        if ($user instanceof User) {
            JoinState::forget(request());
            $slug = $completer->complete($user, $state);

            if ($slug === null) {
                $this->addError('role', __('onboarding.code.invalid'));

                return;
            }

            $this->redirectRoute('building.home', ['building' => $slug]);

            return;
        }

        $this->redirectRoute('join.access');
    }

    private function enter(InviteResolver $resolver): ?ResolvedInvite
    {
        $state = JoinState::get(request());

        if ($state === null) {
            return null;
        }

        try {
            return $resolver->resume($state->invite);
        } catch (HouseClosed) {
            $this->closed = true;
            JoinState::forget(request());

            return null;
        }
    }

    public function render(InviteResolver $resolver): View
    {
        $data = ['resolved' => null];

        if (! $this->closed) {
            $resolved = $this->enter($resolver);

            if ($resolved !== null && CurrentBuilding::has()) {
                $building = CurrentBuilding::get();
                $grid = app(BuildingGrid::class)->forCurrentBuilding([$resolved->sectionNumber]);
                $notice = BuildingPrivacyNotice::query()->whereKey($resolved->buildingNoticeId)->with('template')->first();

                $data = [
                    'resolved' => $resolved,
                    'building' => $building,
                    'section' => $grid['sections'][0] ?? null,
                    'controller' => $notice?->controller_snapshot,
                    'platformNotice' => PrivacyNoticeVersion::current(PrivacyNoticeKind::PlatformAccount),
                ];
            }
        }

        return view('livewire.onboarding.choose-role', $data)->title(__('onboarding.role.title'));
    }
}

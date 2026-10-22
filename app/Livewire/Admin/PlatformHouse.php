<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Auth\StepUpService;
use App\Enums\MembershipRole;
use App\Models\Building;
use App\Models\User;
use App\Platform\PlatformHouses;
use App\Services\Memberships\SelfLinkRefused;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * GET /admin/houses/{slug} — the platform admin's page for ONE house (FIRSTRUN-001). It answers "why is this house
 * empty for me?" in plain words and offers the next step:
 * - opening status (L-core x of 4, roster, parking), structure counts, open DPA requests;
 * - "Jūsu loma šajā mājā" → "Kļūt par šīs mājas administratoru" (strong step-up, own account only, mandate ref) or
 *   "Atvērt mājas paneli";
 * - "Jūsu dzīvoklis" → "Piesaistīt mani dzīvoklim" (strong step-up, approved at once — documented exception), or
 *   "Māja vēl nav atvērta" with the way to the wizard.
 * Platform admins only (404 for everyone else, like any unknown page); needs a fresh second factor like the platform
 * panel. Reads go through PlatformBuildingContext (purpose house_overview, audited) once per page open and after an
 * action; no resident data is shown.
 */
#[Layout('layouts::app')]
final class PlatformHouse extends Component
{
    #[Locked]
    public int $buildingId = 0;

    /** @var array<string, mixed> */
    #[Locked]
    public array $house = [];

    public string $mandateRef = '';

    public string $apartmentNumber = '';

    public string $role = 'owner';

    public ?string $refused = null;

    public ?string $done = null;

    public function mount(Building $building, StepUpService $stepUp): void
    {
        $user = $this->user();
        abort_unless($user->isPlatformAdmin(), 404);

        $this->buildingId = (int) $building->id;

        if (! $stepUp->hasFreshMfa($user)) {
            $this->redirect($stepUp->ask('mfa', request()->getRequestUri()));

            return;
        }

        $this->load();
    }

    public function claimAdmin(StepUpService $stepUp, PlatformHouses $houses): void
    {
        $this->validate(['mandateRef' => ['nullable', 'string', 'max:200']]);
        $user = $this->user();

        // Granting a role is a dangerous action even for oneself here: one passkey tap / TOTP of this session.
        if (! $stepUp->demandStrong($user, $this)) {
            return;
        }

        try {
            $houses->claimAdmin($user, $this->building(), $this->mandateRef !== '' ? $this->mandateRef : null);
            $this->done = 'claimed';
            $this->refused = null;
        } catch (SelfLinkRefused $e) {
            $this->refused = $e->reason;
        } catch (AuthorizationException) {
            $this->refused = 'not_allowed';
        }

        $this->load();
    }

    public function linkSelf(StepUpService $stepUp, PlatformHouses $houses): void
    {
        $this->validate([
            'apartmentNumber' => ['required', 'integer', 'min:1', 'max:99999'],
            'role' => ['required', 'in:owner,tenant,family'],
        ]);
        $user = $this->user();

        if (! $stepUp->demandStrong($user, $this)) {
            return;
        }

        try {
            $houses->linkSelf($user, $this->building(), (int) $this->apartmentNumber, MembershipRole::from($this->role));
            $this->done = 'linked';
            $this->refused = null;
        } catch (SelfLinkRefused $e) {
            $this->refused = $e->reason;
        } catch (AuthorizationException) {
            $this->refused = 'not_allowed';
        }

        $this->load();
    }

    public function render(): View
    {
        $slug = (string) ($this->house['slug'] ?? '');

        return view('livewire.admin.platform-house', [
            'h' => $this->house,
            'panelUrl' => url('/admin/b/'.$slug),
            'wizardUrl' => url('/admin/b/'.$slug.'/opening'),
            'requestsUrl' => url('/admin/platform/opening-requests'),
        ])->title((string) ($this->house['name'] ?? __('start.house.title')))
            ->layoutData([
                'view' => 'admin',
                // ViewSwitch::residentTarget opens /b/{slug} only with a role there, otherwise /home.
                'residentHref' => route('view.switch', array_filter(['mode' => 'resident', 'b' => $slug])),
            ]);
    }

    private function load(): void
    {
        $this->house = PlatformHouses::read($this->building(), $this->user());
    }

    private function building(): Building
    {
        return Building::query()->findOrFail($this->buildingId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}

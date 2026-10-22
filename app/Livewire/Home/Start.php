<?php

declare(strict_types=1);

namespace App\Livewire\Home;

use App\Models\User;
use App\Platform\PlatformHouses;
use App\Support\ViewSwitch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * GET /start — "Jums vēl nav piesaistīta dzīvokļa" (FIRSTRUN-001): the resident landing for a signed-in person with
 * no apartment (where /home sends a platform_admin without any house link; it used to bounce to the empty platform
 * dashboard). Explains how to join (the code from the letter → /join) and, for administrators, where to go next:
 * "Administrēšana" and — for a platform_admin — every house with "Kļūt par administratoru" / "Piesaistīt mani
 * dzīvoklim" on the platform house page. Nothing here writes.
 */
#[Layout('layouts::app')]
final class Start extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('livewire.home.start', [
            'canAdmin' => ViewSwitch::canSwitch($user),
            'houses' => $user->isPlatformAdmin() ? PlatformHouses::overview($user) : [],
        ])->title(__('start.no_home.title'));
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Elder;

use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Palīdzība kaimiņiem" (UI-005, DESIGN-SYSTEM §6.9) — resident app, not Filament. Route middleware:
 * resident group + role:section_elder|building_admin + step-up.
 */
#[Layout('layouts::app')]
final class Panel extends Component
{
    #[Url(as: 'tab', except: 'approvals')]
    public string $tab = 'approvals';

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, ['approvals', 'assisted', 'pin'], true) ? $tab : 'approvals';
    }

    public function render(): View
    {
        return view('livewire.elder.panel', ['building' => CurrentBuilding::get()])->title(__('elder.title'));
    }
}

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
    private const ICONS = [
        'approvals' => 'list-checks',
        'assisted' => 'hand-helping',
        'pin' => 'key-round',
        'proxy' => 'phone-call',
        'notice' => 'megaphone',
        'digest' => 'message-square-share',
    ];

    #[Url(as: 'tab', except: 'approvals')]
    public string $tab = 'approvals';

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, $this->tabs(), true) ? $tab : 'approvals';
    }

    public function render(): View
    {
        if (! in_array($this->tab, $this->tabs(), true)) {
            $this->tab = 'approvals';
        }

        return view('livewire.elder.panel', [
            'building' => CurrentBuilding::get(),
            'tabs' => array_intersect_key(self::ICONS, array_flip($this->tabs())),
        ])->title(__('elder.title'));
    }

    /**
     * Available tabs: Package A tools always; proxy report + digest with the heating module, notices with outages.
     *
     * @return list<string>
     */
    private function tabs(): array
    {
        $settings = CurrentBuilding::get()->settings;
        $tabs = ['approvals', 'assisted', 'pin'];

        if ($settings->moduleEnabled('heating')) {
            $tabs[] = 'proxy';
        }
        if ($settings->moduleEnabled('outages')) {
            $tabs[] = 'notice';
        }
        if ($settings->moduleEnabled('heating')) {
            $tabs[] = 'digest';
        }

        return $tabs;
    }
}

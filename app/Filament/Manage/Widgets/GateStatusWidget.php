<?php

declare(strict_types=1);

namespace App\Filament\Manage\Widgets;

use App\Enums\BuildingRoleType;
use App\Filament\Manage\Pages\HouseOpeningWizardPage;
use App\Models\User;
use App\Services\Opening\OpeningChecklist;
use App\Tenancy\CurrentBuilding;
use Filament\Widgets\Widget;

/**
 * "Kas vēl trūkst" (ROUND2-SPEC §3.7, UI-011, AC-R2-07): three gate cards — Pieteikšanās (L-core), Iedzīvotāju
 * saraksts (L-R), Stāvvieta (L0 / L1 / L2 + module) — each with a status chip (icon + word), up to three missing items
 * in plain words (+ "un vēl N") and "Turpināt" → opening#step-<first missing>. Officers see only the parking card;
 * elders see L-core + roster as read-only text (no button).
 */
final class GateStatusWidget extends Widget
{
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.manage.widgets.gate-status';

    public static function canView(): bool
    {
        return HouseOpeningWizardPage::canAccess();
    }

    /**
     * @return array{cards: array<string, array{open: bool, chip: string, missing: list<string>, more: int, step: ?string}>, buttons: bool, officerOnly: bool, url: string}
     */
    public function data(): array
    {
        $building = CurrentBuilding::get();
        /** @var User $user */
        $user = auth()->user();
        $admin = $user->hasRoleIn($building, BuildingRoleType::BuildingAdmin);
        $elder = $user->hasRoleIn($building, BuildingRoleType::SectionElder);
        $officer = $user->hasRoleIn($building, BuildingRoleType::ParkingOfficer);

        $cards = OpeningChecklist::for($building)->gateCards();
        if (! $admin) {
            $visible = [...($elder ? ['core', 'roster'] : []), ...($officer ? ['parking'] : [])];
            $cards = array_intersect_key($cards, array_flip($visible));
        }

        return [
            'cards' => $cards,
            'buttons' => $admin || $officer,
            'officerOnly' => ! $admin && ! $elder,
            'url' => HouseOpeningWizardPage::getUrl(tenant: $building),
        ];
    }
}

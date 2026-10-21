<?php

declare(strict_types=1);

namespace App\Filament\Manage\Widgets;

use App\Enums\BuildingRoleType;
use App\Filament\Manage\Pages\TeamPage;
use App\Models\BuildingRole;
use App\Models\RoleInvitation;
use App\Services\Opening\OpeningChecklist;
use App\Tenancy\CurrentBuilding;
use Filament\Widgets\Widget;

/**
 * Team at a glance (ROUND2-SPEC §3.7, UI-011): how many people hold each role, stairwells without an elder
 * ("5. k.t.: nav vecākā") and pending invitations — counts only, no names. Visible to whoever can open Komanda.
 */
final class TeamWidget extends Widget
{
    protected static ?int $sort = -5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.manage.widgets.team';

    public static function canView(): bool
    {
        return TeamPage::canAccess();
    }

    /**
     * @return array{roles: array<string, int>, without: list<int>, pending: int, url: string}
     */
    public function data(): array
    {
        $building = CurrentBuilding::get();
        $counts = BuildingRole::query()->current()->where('role', '!=', BuildingRoleType::ManagerViewer->value)
            ->selectRaw('role, count(DISTINCT user_id) AS n')->groupBy('role')->pluck('n', 'role');

        $roles = [];
        foreach ([BuildingRoleType::BuildingAdmin, BuildingRoleType::SectionElder, BuildingRoleType::ParkingOfficer, BuildingRoleType::Volunteer] as $role) {
            $roles[$role->value] = (int) ($counts[$role->value] ?? 0);
        }

        $without = array_keys(array_filter(OpeningChecklist::for($building)->eldersPerSection(), static fn (int $n): bool => $n === 0));

        return [
            'roles' => $roles,
            'without' => $without,
            'pending' => RoleInvitation::query()->pending()->count(),
            'url' => TeamPage::getUrl(tenant: $building),
        ];
    }
}

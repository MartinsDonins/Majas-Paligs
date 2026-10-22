<?php

declare(strict_types=1);

namespace App\Services\Opening;

use App\Enums\BuildingRoleType;
use App\Filament\Manage\Pages\BuildingSettingsPage;
use App\Filament\Manage\Pages\HouseOpeningWizardPage;
use App\Filament\Manage\Pages\InviteBatchPage;
use App\Filament\Manage\Pages\ParkingStatusPage;
use App\Filament\Manage\Pages\PermitImportPage;
use App\Filament\Manage\Pages\RosterImportPage;
use App\Filament\Manage\Pages\TeamPage;
use App\Models\ApartmentInvite;
use App\Models\Building;
use App\Models\Permit;
use App\Models\ResidentContact;
use App\Models\RoleInvitation;
use App\Models\User;

/**
 * "Sākt šeit" — the building admin's getting-started checklist on the house dashboard (FIRSTRUN-001):
 * become admin → open the house (wizard) → heating + outages on → invite the team → import the roster → import the
 * permits → print the invite letters → link yourself to your apartment. Each step has a status (done / todo /
 * blocked by a gate / optional), one plain-language line and ONE link to the page that does it. A pure read of the
 * CURRENT building (counts only, no personal data).
 */
final class GettingStarted
{
    public const DONE = 'done';

    public const TODO = 'todo';

    public const BLOCKED = 'blocked';

    public const OPTIONAL = 'optional';

    /**
     * @return list<array{key: string, status: string, detail: string, url: string, action: string}>
     */
    public static function for(Building $building, User $user): array
    {
        $checklist = OpeningChecklist::for($building);
        $core = $checklist->core->open;
        $coreProgress = $checklist->progress('core');
        $settings = $building->settings;
        $wizard = HouseOpeningWizardPage::getUrl(tenant: $building);

        $isAdmin = $user->isBuildingAdmin($building);
        $elders = $checklist->eldersPerSection();
        $without = array_keys(array_filter($elders, static fn (int $n): bool => $n === 0));
        $invitations = RoleInvitation::query()->pending()->count();
        $modulesOn = $settings->moduleEnabled('heating') && $settings->moduleEnabled('outages');
        $contacts = $checklist->roster->open ? ResidentContact::query()->count() : 0;
        $permits = $checklist->parking->l1() ? Permit::query()->count() : 0;
        $letters = ApartmentInvite::query()->count();
        $member = $user->membershipFor($building);

        $steps = [];

        $steps[] = self::step('admin', $isAdmin ? self::DONE : self::TODO, __('start.steps.admin.'.($isAdmin ? 'done' : 'todo')), TeamPage::getUrl(tenant: $building), 'team');

        $steps[] = self::step('opening', $core ? self::DONE : self::TODO,
            $core ? __('start.steps.opening.done') : __('start.steps.opening.todo', ['done' => $coreProgress['done'], 'total' => $coreProgress['total']]),
            $wizard, 'wizard');

        $steps[] = self::step('modules', match (true) {
            $modulesOn => self::DONE,
            ! $core => self::BLOCKED,
            default => self::TODO,
        }, __('start.steps.modules.'.($modulesOn ? 'done' : ($core ? 'todo' : 'blocked'))), BuildingSettingsPage::getUrl(tenant: $building), 'settings');

        $teamDone = $elders !== [] && $without === [];
        $steps[] = self::step('team', $teamDone ? self::DONE : self::OPTIONAL,
            $teamDone ? __('start.steps.team.done') : __('start.steps.team.todo', ['sections' => implode(', ', $without), 'invitations' => $invitations]),
            TeamPage::getUrl(tenant: $building), 'team');

        $steps[] = self::step('roster', match (true) {
            ! $checklist->roster->open => self::BLOCKED,
            $contacts > 0 => self::DONE,
            default => self::OPTIONAL,
        }, ! $checklist->roster->open ? __('start.steps.roster.blocked') : ($contacts > 0 ? trans_choice('start.steps.roster.done', $contacts, ['count' => $contacts]) : __('start.steps.roster.todo')),
            $checklist->roster->open ? RosterImportPage::getUrl(tenant: $building) : $wizard.'#step-roster_scope', $checklist->roster->open ? 'import' : 'wizard');

        $steps[] = self::step('permits', match (true) {
            ! $checklist->parking->l1() => self::BLOCKED,
            $permits > 0 => self::DONE,
            default => self::OPTIONAL,
        }, ! $checklist->parking->l1() ? __('start.steps.permits.blocked') : ($permits > 0 ? trans_choice('start.steps.permits.done', $permits, ['count' => $permits]) : __('start.steps.permits.todo')),
            $checklist->parking->l1() ? PermitImportPage::getUrl(tenant: $building) : ParkingStatusPage::getUrl(tenant: $building), $checklist->parking->l1() ? 'import' : 'parking');

        $steps[] = self::step('letters', match (true) {
            ! $core => self::BLOCKED,
            $letters > 0 => self::DONE,
            default => self::TODO,
        }, __('start.steps.letters.'.(! $core ? 'blocked' : ($letters > 0 ? 'done' : 'todo'))), InviteBatchPage::getUrl(tenant: $building), 'letters');

        $steps[] = self::step('me', match (true) {
            $member !== null => self::DONE,
            ! $core => self::BLOCKED,
            default => self::OPTIONAL,
        }, __('start.steps.me.'.($member !== null ? 'done' : ($core ? 'todo' : 'blocked'))), route('building.me', ['building' => $building->slug]).'#link-myself', 'me');

        return $steps;
    }

    /**
     * @return array{key: string, status: string, detail: string, url: string, action: string}
     */
    private static function step(string $key, string $status, string $detail, string $url, string $action): array
    {
        return ['key' => $key, 'status' => $status, 'detail' => $detail, 'url' => $url, 'action' => $action];
    }

    /**
     * @param  list<array{status: string}>  $steps
     * @return array{done: int, total: int}
     */
    public static function progress(array $steps): array
    {
        return ['done' => count(array_filter($steps, static fn (array $s): bool => $s['status'] === self::DONE)), 'total' => count($steps)];
    }

    /** Role holders other than building_admin are not shown the checklist (their dashboards keep the gate cards). */
    public static function visibleTo(User $user, Building $building): bool
    {
        return $user->hasRoleIn($building, BuildingRoleType::BuildingAdmin);
    }
}

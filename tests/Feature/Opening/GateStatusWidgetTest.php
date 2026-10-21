<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Filament\Manage\Pages\HouseOpeningWizardPage;
use App\Filament\Manage\Pages\ParkingStatusPage;
use App\Filament\Manage\Widgets\GateStatusWidget;
use App\Filament\Manage\Widgets\TeamWidget;
use App\Gdpr\CoreGate;
use App\Models\Building;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * AC-R2-07 — the dashboard shows the three gate cards with the exact missing items; each "Turpināt" opens the matching
 * wizard step. Officers see only the parking card; elders see L-core + roster without buttons. UI-011 parking status.
 */

function gateViewer(User $user, Building $b): void
{
    test()->actingAs($user);
    app(StepUpService::class)->markFresh($user, 'totp');
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($b);
}

it('lists the exact missing items per gate and links "Turpināt" to the first missing step', function (): void {
    $b = makeBuilding('cards-empty', 2, 1);
    $admin = makeUser('cards-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    gateViewer($admin, $b);
    $wizard = HouseOpeningWizardPage::getUrl(tenant: $b);
    $core = inBuilding($b, fn () => CoreGate::status($b));

    $widget = inBuilding($b, fn () => Livewire::actingAs($admin)->test(GateStatusWidget::class));
    foreach (array_slice($core->missingLabels(), 0, 3) as $label) {
        $widget->assertSee($label);
    }
    $widget->assertSee(__('opening.dashboard.more', ['count' => count($core->missing) - 3]))
        ->assertSee(__('gate.roster.missing.dpa_scope_roster'))
        ->assertSee(__('gate.parking.missing.dpa_scope_parking'))
        ->assertSee(__('opening.chip.l0'))
        ->assertSeeHtml($wizard.'#step-controller');

    // Controller done → core and roster continue at the DPA.
    $b->forceFill(['settings' => $b->settings->with(['controller' => ['name' => 'Testa biedrība', 'contact' => 'dati@example.test']])])->save();
    inBuilding($b, fn () => Livewire::actingAs($admin)->test(GateStatusWidget::class)
        ->assertSeeHtml($wizard.'#step-dpa')->assertDontSeeHtml($wizard.'#step-controller'));

    // L-core open → core card open; roster continues at roster_scope, parking at parking_scope.
    openGate($b);
    inBuilding($b, fn () => Livewire::actingAs($admin)->test(GateStatusWidget::class)
        ->assertSee(__('opening.chip.open'))
        ->assertSeeHtml($wizard.'#step-roster_scope')
        ->assertSeeHtml($wizard.'#step-parking_scope'));
});

it('shows the parking steps between L1 and L2 and the module switch at L2', function (): void {
    $b = parkingHouse('cards-l1', level: 1);
    $admin = makeUser('cards-l1-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    gateViewer($admin, $b);
    $wizard = HouseOpeningWizardPage::getUrl(tenant: $b);

    inBuilding($b, fn () => Livewire::actingAs($admin)->test(GateStatusWidget::class)
        ->assertSee(__('opening.chip.l1'))
        ->assertSee(__('opening.dashboard.parking_l1'))
        ->assertSee(__('gate.parking.missing.dpia_completed_at'))
        ->assertSeeHtml($wizard.'#step-dpia'));

    $b2 = parkingHouse('cards-l2', level: 2, module: false);
    grantRole($admin, $b2, BuildingRoleType::BuildingAdmin);
    gateViewer($admin, $b2);
    inBuilding($b2, fn () => Livewire::actingAs($admin)->test(GateStatusWidget::class)
        ->assertSee(__('opening.chip.l2_off'))
        ->assertSeeHtml(HouseOpeningWizardPage::getUrl(tenant: $b2).'#step-parking_on'));
});

it('shows officers only the parking card and elders the core + roster cards without buttons', function (): void {
    $b = makeBuilding('cards-roles', 1, 1);
    $officer = parkingOfficer($b, 'cards-officer@example.test');
    $elder = makeUser('cards-elder@example.test');
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);

    gateViewer($officer, $b);
    inBuilding($b, fn () => Livewire::actingAs($officer)->test(GateStatusWidget::class)
        ->assertSeeHtml('data-testid="gate-parking"')
        ->assertDontSeeHtml('data-testid="gate-core"')
        ->assertDontSeeHtml('data-testid="gate-roster"')
        ->assertSeeHtml('data-testid="gate-parking-continue"'));

    gateViewer($elder, $b);
    inBuilding($b, fn () => Livewire::actingAs($elder)->test(GateStatusWidget::class)
        ->assertSeeHtml('data-testid="gate-core"')
        ->assertSeeHtml('data-testid="gate-roster"')
        ->assertDontSeeHtml('data-testid="gate-parking"')
        ->assertDontSeeHtml('-continue"'));
});

it('counts the team and stairwells without an elder', function (): void {
    $b = makeBuilding('cards-team', 2, 1);
    $admin = makeUser('cards-team-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $elder = makeUser('cards-team-elder@example.test');
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);
    gateViewer($admin, $b);

    inBuilding($b, fn () => Livewire::actingAs($admin)->test(TeamWidget::class)
        ->assertSee(__('enum.building_role.section_elder').': 1')
        ->assertSee(__('opening.team.section_no_elder', ['n' => 2]))
        ->assertDontSee(__('opening.team.section_no_elder', ['n' => 1])));
});

it('explains the parking level on "Stāvvietas statuss" with links to the wizard (admin and officer only)', function (): void {
    $b = makeBuilding('status-l0', 1, 1, open: true);
    $officer = parkingOfficer($b, 'status-officer@example.test');
    gateViewer($officer, $b);

    inBuilding($b, fn () => Livewire::actingAs($officer)->test(ParkingStatusPage::class)
        ->assertSee(__('opening.parking_status.residents_none'))
        ->assertSee(__('gate.parking.missing.dpa_scope_parking'))
        ->assertSeeHtml(HouseOpeningWizardPage::getUrl(tenant: $b).'#step-parking_scope'));

    $elder = makeUser('status-elder@example.test');
    grantRole($elder, $b, BuildingRoleType::SectionElder, 1);
    steppedUp($elder);
    $this->actingAs($elder)->get('/admin/b/status-l0/parking-status')->assertForbidden();
});
